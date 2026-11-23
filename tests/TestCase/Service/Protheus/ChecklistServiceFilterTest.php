<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ChecklistServiceFilter;
use App\Service\Protheus\OrderEntryExportService;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use App\Service\Protheus\ProtheusSectorQueries;
use App\Service\Protheus\SectorPresentationScope;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChecklistServiceFilterTest extends TestCase
{
    public function testServiceOptionsUseVerifiedTotvsCodesAndRequestedLabels(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->expects(self::once())->method('execute')->willReturnCallback(function ($sql) {
            self::assertSame(ChecklistServiceFilter::options(), $sql);
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertStringNotContainsString('TJ_CODBEM', $sql);
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn(array_map(static fn($code) => ['code' => $code],
                ['PREVLA', 'PRELUB', 'PREMEC', 'PREELE']));
            return $statement;
        });
        $options = (new OrderListingService(new ProtheusRepository($connection)))->checklistServices();
        self::assertSame(['PREELE', 'PREMEC', 'PRELUB', 'PREVLA'], array_keys($options));
        self::assertSame(['PREVELE — Elétrica', 'PREVMEC — Mecânica', 'PREVLUB — Lubrificação', 'PREVLAM — Laminadores'], array_values($options));
    }

    public function testOnlyRecognizedRoutesWithSelectedServiceAreReturnedAndCounted(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE orders (TJ_ORDEM TEXT, TJ_CODBEM TEXT, TJ_CODAREA TEXT, TJ_SERVICO TEXT, D_E_L_E_T_ TEXT)');
        $db->exec("INSERT INTO orders VALUES
            ('001', 'PREVMEC FAB', 'MECANI', 'PREELE', ''),
            ('002', 'PREVLUB FAB 01', 'MECANI', 'PRELUB', ''),
            ('003', 'PREVELE FAB 01', 'INSTRU', 'PREELE', ''),
            ('004', 'BOMBA', 'MECANI', 'CORRET', ''),
            ('005', 'PREVMEC FAB', 'FAB', 'CORRET', ''),
            ('006', 'PREVMEC FAB', 'MECANI', 'PREELE', '*'),
            ('007', 'LAM 01', 'MECANI', 'PREVLA', ''),
            ('008', 'BEM', 'FAB', 'PREMEC', '')");
        $where = " FROM orders j WHERE j.D_E_L_E_T_ <> '*'";
        foreach (['' => ['001', '002', '003', '007', '008'], 'PREELE' => ['001', '003'], 'PRELUB' => ['002'], 'PREVLA' => ['007'], 'PREMEC' => ['008']] as $service => $expected) {
            $params = ['checklist_service' => $service, 'checklist_service_code' => $service];
            $statement = $db->prepare(ChecklistServiceFilter::apply('SELECT j.TJ_ORDEM' . $where) . ' ORDER BY j.TJ_ORDEM');
            $statement->execute($params);
            self::assertSame($expected, $statement->fetchAll(\PDO::FETCH_COLUMN));
            $statement = $db->prepare(ChecklistServiceFilter::apply('SELECT COUNT(*)' . $where));
            $statement->execute($params);
            self::assertSame(count($expected), (int)$statement->fetchColumn());
        }
    }

    public function testScopeIsAllowlistedAndReversibleBeforePagingAndAggregation(): void
    {
        foreach ([ProtheusQueries::orders(false, false, false),
            ProtheusQueries::orders(true, true, true, true, false, false, true),
            ProtheusQueries::generalEntries(), ProtheusSectorQueries::backlog(),
            ProtheusSectorQueries::page(true), ProtheusSectorQueries::entriesPage()] as $base) {
            $sql = ChecklistServiceFilter::apply($base);
            self::assertNotSame($base, $sql);
            self::assertSame($base, ChecklistServiceFilter::remove($sql));
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertTrue(ProtheusQueries::allows(SectorPresentationScope::dashboardPeriod($sql)));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
            self::assertStringContainsString('j.TJ_SERVICO = CAST(:checklist_service_code', $sql);
            self::assertSame(1, substr_count($sql, ':checklist_service '));
            self::assertSame(1, substr_count($sql, ':checklist_service_code '));
        }
        self::assertTrue(ProtheusQueries::allows(ChecklistServiceFilter::options()));
    }

    public function testAllServicesClearsInactiveChecklistSelection(): void
    {
        self::assertSame('', ChecklistServiceFilter::normalize(['checklist_service' => '000001'])['checklist_service']);
        $this->expectException(InvalidArgumentException::class);
        ChecklistServiceFilter::normalize(['service_scope' => 'invalid']);
    }

    public function testListingCountAndBothExportsUseSameScopeAndKeepBatchOffsets(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params) use (&$calls) {
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertNotSame($sql, ChecklistServiceFilter::remove($sql));
            self::assertSame('PREELE', $params['checklist_service']);
            self::assertSame('PREELE', $params['checklist_service_code']);
            self::assertSame('CC01', $params['centro']);
            self::assertSame('MECANI', $params['area']);
            self::assertSame('2026-11-01', $params['date_start']);
            $calls[] = [$sql, $params];
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn(str_contains($sql, 'AS total') ? [['total' => 7]] : []);
            return $statement;
        });
        $repository = new ProtheusRepository($connection);
        $query = ['service_scope' => 'checklist', 'checklist_service' => 'PREELE',
            'os' => '000001', 'centro' => 'CC01', 'area' => 'MECANI', 'unidade' => 'factory',
            'bem' => 'PREVMEC FAB', 'situacao' => 'L', 'date_start' => '2026-11-01', 'date_end' => '2026-11-30'];
        $listing = new OrderListingService($repository);
        $result = $listing->load($query);
        self::assertTrue($result['available']);
        self::assertSame(7, $result['total']);
        self::assertSame('PREELE', $result['filters']['checklist_service']);
        self::assertTrue($listing->load($query, true, 3)['available']);
        self::assertTrue((new OrderEntryExportService($repository))->loadGeneral($query, 3)['available']);
        self::assertCount(4, $calls);
        self::assertSame(2 * \App\Service\StreamingXlsxReport::BATCH_SIZE, $calls[2][1]['offset']);
        self::assertSame($calls[2][1]['offset'], $calls[3][1]['offset']);
        self::assertStringContainsString('INNER JOIN dbo.STL010', $calls[3][0]);
    }
}
