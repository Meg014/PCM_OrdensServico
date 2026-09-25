<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\OrderEntryExportService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class OrderEntryExportServiceTest extends TestCase
{
    public function testBatchQueryAppliesFiltersAndReturnsOneRowPerEntryWithoutNPlusOne(): void
    {
        $calls = [];
        $rows = [$this->entry('M', '008382'), $this->entry('M', '009999'), $this->entry('P', '002075')];
        $result = (new OrderEntryExportService($this->repository($calls, $rows)))->load('ELETRI', [
            'status' => 'EM ABERTO', 'equipment' => 'VAU 50 003', 'maintenance_type' => 'COR',
            'q' => 'motor%_', 'entry_type' => 'M', 'professional' => '008382',
        ]);
        self::assertTrue($result['available']);
        self::assertCount(3, $result['entries']);
        self::assertCount(1, $calls);
        [$sql, $params] = $calls[0];
        self::assertTrue(ProtheusQueries::allows($sql));
        foreach (['dbo.STL010', 'dbo.ST1010', 'dbo.SB1010'] as $table) self::assertStringContainsString($table, $sql);
        self::assertStringContainsString('INNER JOIN dbo.STL010', $sql); // OS without entries are intentionally excluded.
        self::assertSame('%motor~%~_%', $params['q']);
        self::assertSame('M', $params['entry_type']);
        self::assertSame('008382', $params['professional']);
        self::assertSame(1001, $params['fetch']);
        self::assertSame('ELETRI', $params['area']);
    }

    public function testUnavailableAndInvalidFiltersAreSafe(): void
    {
        $calls = [];
        self::assertFalse((new OrderEntryExportService($this->repository($calls, [], true)))->load('ELETRI', [])['available']);
        self::assertCount(1, $calls);
        $this->expectException(\InvalidArgumentException::class);
        (new OrderEntryExportService())->load('ELETRI', ['entry_type' => 'SQL']);
    }

    public function testGeneralExportSupportsAllAreasAndPreservesFiltersAcrossBatches(): void
    {
        $calls = [];
        $rows = [$this->entry('M', '008382'), array_replace($this->entry('P', '002075'), ['TJ_CODAREA' => 'MECANI'])];
        $service = new OrderEntryExportService($this->repository($calls, $rows));
        foreach ([1, 2] as $page) {
            $result = $service->loadGeneral(['os' => '005472', 'bem' => '000045', 'centro' => '0001',
                'area' => '', 'date_start' => '2026-01-01', 'date_end' => '2026-12-31'], $page);
            self::assertTrue($result['available']);
        }
        self::assertCount(2, $calls);
        foreach ($calls as $call) {
            self::assertSame('005472', $call[1]['numero']);
            self::assertSame('000045', $call[1]['bem']);
            self::assertSame('', $call[1]['area']);
            self::assertStringContainsString('dbo.STL010', $call[0]);
            self::assertTrue(ProtheusQueries::allows($call[0]));
        }
        self::assertSame(0, $calls[0][1]['offset']);
        self::assertSame(1000, $calls[1][1]['offset']);
    }

    private function repository(array &$calls, array $rows, bool $fail = false): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use (&$calls, $rows, $fail) {
            $calls[] = [$sql, $params, $types];
            if ($fail) throw new \RuntimeException('SQLSTATE secret');
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->expects(self::once())->method('closeCursor');
            return $statement;
        });
        return new ProtheusRepository($connection);
    }

    private function entry(string $type, string $code): array
    {
        return ['record_id' => 1, 'TJ_FILIAL' => '01', 'TJ_ORDEM' => '005472', 'descricao' => 'DESCRIÇÃO',
            'TJ_CODBEM' => 'VAU 50 003', 'equipment_name' => 'VAU', 'TJ_SERVICO' => '001',
            'service_name' => 'SERVIÇO', 'TJ_CODAREA' => 'ELETRI', 'TJ_CCUSTO' => '0001',
            'TJ_TIPO' => 'COR', 'status' => 'EM ABERTO', 'TL_TIPOREG' => $type, 'TL_CODIGO' => $code,
            'identity_count' => 1, 'equipment_matches' => 1, 'service_matches' => 1,
            'professional_matches' => $type === 'M' ? 1 : 0, 'product_matches' => $type === 'P' ? 1 : 0];
    }
}
