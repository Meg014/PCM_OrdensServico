<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ProtheusQueries as Q;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class ProtheusScopeTest extends TestCase
{
    public function testScopeIsBoundForListingDetailIdentityHistoryAndMenu(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly([]));
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use (&$calls) {
            self::assertTrue(Q::allows($sql));
            if ($sql === Q::HISTORY_USER_COLUMNS) {
                $rows = [['inicio' => null, 'fim' => null]];
            } else {
                self::assertStringContainsString('j.TJ_CODAREA = CAST(:scope_area AS VARCHAR(100))', $sql);
                self::assertSame('ELETRI', $params['scope_area']);
                self::assertSame('string', $types['scope_area']);
                $calls[] = $sql;
                $rows = [];
            }
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);

            return $statement;
        });
        $repo = new ProtheusRepository($connection, areaScope: 'ELETRI');
        self::assertSame([], $repo->findOrders('004368', '01')['orders']);
        self::assertNull($repo->findOrder('004368', '01'));
        self::assertNull($repo->findOrderIdentity('004368', '01'));
        self::assertSame([], $repo->findEquipmentHistory('MEL 80 115', '01')['orders']);
        self::assertSame([], $repo->findAreas());
        self::assertCount(5, $calls); // Rejected order never loads resources or master records.
    }

    public function testScopedSqlRemainsClosedAndFiltersBeforePagination(): void
    {
        $queries = [Q::ORDER, Q::ORDER_BRANCH, Q::ORDER_IDENTITY, Q::ENTRIES, Q::AREAS];
        foreach ([false, true] as $branch) {
            $queries[] = Q::equipmentHistory($branch);
            $queries[] = Q::orders(true, $branch, true, true);
        }
        foreach ($queries as $query) {
            $sql = Q::withAreaScope($query);
            self::assertNotSame($query, $sql);
            self::assertTrue(Q::allows($sql));
            self::assertFalse(Q::allows($sql . '; SELECT 2'));
            self::assertFalse(Q::allows(str_replace(':scope_area', "'ELETRI' OR 1=1", $sql)));
            if (str_contains($sql, 'OFFSET')) {
                self::assertLessThan(strpos($sql, 'OFFSET'), strpos($sql, ':scope_area'));
            }
        }
    }
}
