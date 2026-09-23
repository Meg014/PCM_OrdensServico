<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ProtheusDashboardService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProtheusDashboardTest extends TestCase
{
    public function testOneBoundQueryAndNoInventedStatus(): void
    {
        $calls = [];
        $rows = [$this->row('total', 50), $this->row('type', 30, 'COR')];
        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load(['filial' => '01', 'bem' => "X'; DELETE--"]);
        self::assertTrue($result['available']);
        self::assertSame(50, $result['record_count']);
        self::assertSame('COR', $result['groups']['type'][0]['code']);
        self::assertSame(array_fill(0, 6, null), array_values($result['indicators']));
        self::assertNotNull($result['queried_at']);
        self::assertCount(1, $calls);
        self::assertSame("X'; DELETE--", $calls[0][1]['bem']);
        self::assertStringNotContainsString("X'; DELETE--", $calls[0][0]);
        self::assertSame('string', $calls[0][2]['filial']);
    }

    public function testClosedBoundedSqlDoesNotHydrateOrders(): void
    {
        $sql = ProtheusQueries::DASHBOARD;
        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        self::assertStringContainsString('GROUP BY GROUPING SETS', $sql);
        self::assertStringContainsString('position <= 10', $sql);
        self::assertStringContainsString('PARTITION BY j.TJ_FILIAL, j.TJ_ORDEM', $sql);
        self::assertStringContainsString("j.D_E_L_E_T_ <> '*'", $sql);
        foreach (['STL010', 'SELECT *', 'FECHADA', 'CANCELADA', 'CORRETIVA'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function testFailureAndAmbiguityNeverBecomeZeroOrLeakErrors(): void
    {
        foreach ([null, [], [$this->row('total', 20) + []]] as $rows) {
            if ($rows !== null && $rows !== []) $rows[0]['identity_count'] = 2;
            $calls = [];
            $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load();
            self::assertFalse($result['available']);
            self::assertNull($result['record_count']);
            self::assertNull($result['queried_at']);
            self::assertSame([], $result['groups']);
            self::assertStringNotContainsString('SQLSTATE', json_encode($result));
        }
    }

    public function testTrueEmptyPortfolioIsZeroOnlyAfterSuccessfulQuery(): void
    {
        $calls = [];
        $result = (new ProtheusDashboardService($this->repository([$this->row('total', 0)], $calls)))->load();
        self::assertTrue($result['available']);
        self::assertSame(0, $result['record_count']);
    }

    private function row(string $dimension, int $quantity, string $code = ''): array
    {
        return compact('dimension', 'quantity', 'code') + ['branch' => '01', 'ending' => '', 'identity_count' => 1];
    }

    private function repository(?array $rows, array &$calls): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use ($rows, &$calls) {
            $calls[] = [$sql, $params, $types];
            if ($rows === null) throw new RuntimeException('SQLSTATE host user password');
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->expects(self::once())->method('closeCursor');
            return $statement;
        });
        return new ProtheusRepository($connection);
    }
}
