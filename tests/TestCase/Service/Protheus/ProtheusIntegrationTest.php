<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ProtheusQueries as Sql;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProtheusIntegrationTest extends TestCase
{
    public function testUnapprovedSqlIsBlockedWithoutConnecting(): void
    {
        $driver = new ProtheusReadOnly();
        foreach ([
            'INSERT INTO STJ010 DEFAULT VALUES', 'UPDATE STJ010 SET TJ_ORDEM = 1',
            'DELETE FROM STJ010', 'DROP TABLE STJ010', 'EXEC procedure_name',
            'SELECT * INTO backup FROM STJ010', Sql::HEALTH . '; DELETE FROM STJ010',
            'SELECT 2', '-- comment' . "\n" . Sql::HEALTH,
        ] as $sql) {
            try {
                $driver->prepare($sql);
                self::fail('SQL não autorizado foi aceito.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('lista permitida', $exception->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        $driver->exec(Sql::HEALTH);
    }

    public function testStartupSqlIsBlockedBeforeConnection(): void
    {
        $driver = new ProtheusReadOnly([
            'host' => 'unused', 'database' => 'unused', 'username' => 'unused', 'password' => 'unused',
            'init' => ['DELETE FROM STJ010'],
        ]);
        $this->expectException(LogicException::class);
        $driver->connect();
    }

    public function testOrderPreservesCodesAndMapsOnlyKnownResourceTypes(): void
    {
        $calls = [];
        $repository = $this->repository([
            Sql::ORDER => [[
                'TJ_ORDEM' => '004893  ', 'TJ_FILIAL' => '01  ', 'TJ_CODBEM' => 'FTR 30 001 ',
                'TJ_SERVICO' => 'COROPE ', 'TJ_OBSERVA' => 'binary',
                'pcm_descricao' => 'LAVAR TODOS 3 FILTRO COM SODA',
            ]],
            Sql::EQUIPMENT => [['T9_CODBEM' => 'FTR 30 001 ']],
            Sql::SERVICE => [['T4_SERVICO' => 'COROPE ']],
            Sql::ENTRIES => [
                ['TL_TIPOREG' => 'M ', 'TL_CODIGO' => '008382 '],
                ['TL_TIPOREG' => 'P ', 'TL_CODIGO' => '002075 '],
                ['TL_TIPOREG' => 'P ', 'TL_CODIGO' => '000110 '],
                ['TL_TIPOREG' => 'E ', 'TL_CODIGO' => 'ELE '],
                ['TL_TIPOREG' => 'T ', 'TL_CODIGO' => 'UNKNOWN '],
            ],
            Sql::PROFESSIONAL => [['T1_CODFUNC' => '008382 ']],
            Sql::PRODUCT => [], // Missing/deleted master must not discard its entry.
        ], $calls);
        $order = $repository->findOrder('004893');
        self::assertSame('004893', $order['numero']);
        self::assertSame('LAVAR TODOS 3 FILTRO COM SODA', $order['descricao']);
        self::assertArrayNotHasKey('TJ_OBSERVA', $order['dados_principais']);
        self::assertSame('008382', $order['mao_de_obra'][0]['profissional']['T1_CODFUNC']);
        self::assertCount(2, $order['materiais']);
        self::assertNull($order['materiais'][0]['produto']);
        self::assertSame(['E', 'T'], array_column($order['outros_apontamentos'], 'TL_TIPOREG'));
        self::assertSame(['numero' => '004893'], $calls[0][1]);
        self::assertSame(['numero' => '004893', 'filial' => '01'], $calls[3][1]);
        foreach ($calls as [$sql, $params, $types]) {
            self::assertTrue(Sql::allows($sql));
            self::assertSame(array_fill_keys(array_keys($params), 'string'), $types);
        }
    }

    public function testMissingOrderAndExplicitBranch(): void
    {
        $calls = [];
        $repository = $this->repository([Sql::ORDER_BRANCH => []], $calls);
        self::assertNull($repository->findOrder('004368', '01'));
        self::assertCount(1, $calls);
        self::assertSame(['numero' => '004368', 'filial' => '01'], $calls[0][1]);
    }

    public function testAmbiguousOrderFailsInsteadOfMixingBranches(): void
    {
        $calls = [];
        $repository = $this->repository([Sql::ORDER => [['TJ_FILIAL' => '01'], ['TJ_FILIAL' => '02']]], $calls);
        $this->expectException(RuntimeException::class);
        $repository->findOrder('004893');
    }

    private function repository(array $results, array &$calls): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use ($results, &$calls) {
            $calls[] = [$sql, $params, $types];
            self::assertArrayHasKey($sql, $results);
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($results[$sql]);
            $statement->expects(self::once())->method('closeCursor');

            return $statement;
        });

        return new ProtheusRepository($connection);
    }
}
