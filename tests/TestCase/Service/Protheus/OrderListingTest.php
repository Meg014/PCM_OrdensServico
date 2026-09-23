<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OrderListingTest extends TestCase
{
    public function testCurrentOrderWithoutLocalSnapshotAndBoundedSqlPage(): void
    {
        $calls = [];
        $row = ['TJ_ORDEM' => '004368 ', 'TJ_FILIAL' => '01 ', 'TJ_TIPO' => 'COR',
            'identity_count' => 1, 'equipment_matches' => 1, 'service_matches' => 1];
        $service = new OrderListingService($this->repository([$row, $row], $calls));
        $result = $service->load(['os' => '004368', 'filial' => '01', 'page' => '2', 'limite' => '1']);
        self::assertTrue($result['available']);
        self::assertTrue($result['has_more']);
        self::assertSame([['TJ_ORDEM' => '004368', 'TJ_FILIAL' => '01', 'TJ_TIPO' => 'COR']], $result['orders']);
        self::assertCount(1, $calls);
        self::assertSame(['offset' => 1, 'fetch' => 2, 'numero' => '004368', 'filial' => '01'], $calls[0][1]);
        self::assertSame('integer', $calls[0][2]['fetch']);
        self::assertSame('string', $calls[0][2]['numero']);
    }

    public function testAllClosedVariantsHaveSafePaginationAndNoResources(): void
    {
        foreach ([false, true] as $number) {
            foreach ([false, true] as $branch) {
                foreach ([false, true] as $equipment) {
                    $sql = ProtheusQueries::orders($number, $branch, $equipment);
                    self::assertTrue(ProtheusQueries::allows($sql));
                    self::assertFalse(ProtheusQueries::allows($sql . '; SELECT 2'));
                    self::assertStringNotContainsString('STL010', $sql);
                    self::assertStringNotContainsString('TJ_OBSERVA', $sql);
                    self::assertStringNotContainsString('j.*', $sql);
                    self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY', $sql);
                    self::assertStringContainsString('ORDER BY p.reference_date DESC, p.R_E_C_N_O_ DESC', $sql);
                    self::assertStringContainsString("TRY_CONVERT(date, NULLIF(j.TJ_DTMRFIM, ''), 112)", $sql);
                    self::assertSame(6, substr_count($sql, "D_E_L_E_T_ <> '*'"));
                }
            }
        }
        $calls = [];
        $injection = "X'; DELETE FROM STJ010;--";
        $result = (new OrderListingService($this->repository([], $calls)))->load(['bem' => $injection]);
        self::assertTrue($result['available']);
        self::assertSame($injection, $calls[0][1]['bem']);
        self::assertStringNotContainsString($injection, $calls[0][0]);
    }

    public function testUnavailableNeverExposesDriverDetailsOrUsesSnapshots(): void
    {
        $calls = [];
        $result = (new OrderListingService($this->repository([], $calls, true)))->load(['os' => '004368']);
        self::assertFalse($result['available']);
        self::assertSame([], $result['orders']);
        self::assertStringNotContainsString('SQLSTATE', json_encode($result));
        self::assertCount(1, $calls);
    }

    public function testAmbiguityFailsSafelyAndEmptyResultIsNotAnOutage(): void
    {
        foreach ([[], [['identity_count' => 2, 'equipment_matches' => 1, 'service_matches' => 1]]] as $rows) {
            $calls = [];
            $result = (new OrderListingService($this->repository($rows, $calls)))->load([]);
            self::assertSame($rows === [], $result['available']);
        }
    }

    public function testInvalidInputDoesNotQuery(): void
    {
        $calls = [];
        $service = new OrderListingService($this->repository([], $calls));
        foreach ([['os' => []], ['page' => '0'], ['limite' => '101']] as $input) {
            try {
                $service->load($input);
                self::fail('Invalid input accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $calls);
            }
        }
    }

    private function repository(array $rows, array &$calls, bool $fail = false): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use ($rows, &$calls, $fail) {
            $calls[] = [$sql, $params, $types];
            self::assertTrue(ProtheusQueries::allows($sql));
            if ($fail) {
                throw new RuntimeException('SQLSTATE sensitive host user password');
            }
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->expects(self::once())->method('closeCursor');

            return $statement;
        });

        return new ProtheusRepository($connection);
    }
}
