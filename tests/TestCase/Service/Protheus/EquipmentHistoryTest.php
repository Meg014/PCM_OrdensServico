<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Command\ProtheusHealthCommand;
use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ProtheusQueries as Sql;
use App\Service\Protheus\ProtheusRepository;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EquipmentHistoryTest extends TestCase
{
    public function testBoundedPagePreservesRawFieldsAndBindsKeysAndIntegers(): void
    {
        $calls = [];
        $repository = $this->repository([
            $this->row('004368'), $this->row('004367'), $this->row('004366'),
        ], $calls, ['inicio' => 25, 'fim' => null]);
        $result = $repository->findEquipmentHistory('MEL 80 115  ', '01 ', 2, 2);
        self::assertSame(['004368', '004367'], array_column($result['orders'], 'TJ_ORDEM'));
        self::assertSame('MOTOR ROSCA RO-02 - SILO 01', $result['orders'][0]['equipment_name']);
        self::assertTrue($result['has_more']);
        self::assertSame('X', $result['orders'][0]['TJ_SITUACA']);
        self::assertSame('TROCA DOS ROLAMENTOS DO MOTOR', $result['orders'][0]['descricao']);
        self::assertSame(['bem' => 'MEL 80 115', 'offset' => 2, 'fetch' => 3, 'filial' => '01'], $calls[1][1]);
        self::assertSame('integer', $calls[1][2]['offset']);
        self::assertSame('integer', $calls[1][2]['fetch']);
        self::assertSame('string', $calls[1][2]['filial']);
        self::assertSame(Sql::equipmentHistory(true, true, false), $calls[1][0]);
        self::assertSame(['TJ_USUAINI' => true, 'TJ_USUAFIM' => false], $result['user_columns']);
        self::assertArrayNotHasKey('equipment_matches', $result['orders'][0]);
        self::assertCount(2, $calls); // Metadata plus history, never STL/ST1/SB1/detail.
        $repository->findEquipmentHistory('MEL 80 115', '01', 3, 2);
        self::assertCount(3, $calls); // Metadata cached within repository instance.
    }

    public function testEmptyHistoryAndAllBranches(): void
    {
        $calls = [];
        $repository = $this->repository([], $calls);
        $result = $repository->findEquipmentHistory('MEL 80 115');
        self::assertSame([], $result['orders']);
        self::assertFalse($result['has_more']);
        self::assertNull($result['branch']);
        self::assertArrayNotHasKey('filial', $calls[1][1]);
        self::assertSame(Sql::equipmentHistory(false), $calls[1][0]);
    }

    public function testParametersNeverBecomeSqlAndBadLimitsNeverQuery(): void
    {
        $calls = [];
        $repository = $this->repository([], $calls);
        $value = "X'; DELETE FROM STJ010;--";
        $repository->findEquipmentHistory($value, '01');
        self::assertSame($value, $calls[1][1]['bem']);
        self::assertStringNotContainsString($value, $calls[1][0]);
        foreach ([[0, 20], [1, 0], [1, 101], [PHP_INT_MAX, 100]] as [$page, $limit]) {
            try {
                $repository->findEquipmentHistory('MEL 80 115', '01', $page, $limit);
                self::fail('Invalid pagination accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(2, $calls);
            }
        }
    }

    public function testAmbiguousMastersOrDuplicateOsAreNotSilentlyChosen(): void
    {
        foreach (['equipment_matches', 'service_matches', 'identity_count'] as $field) {
            $calls = [];
            $row = $this->row('004368');
            $row[$field] = 2;
            $repository = $this->repository([$row], $calls);
            try {
                $repository->findEquipmentHistory('MEL 80 115', '01');
                self::fail('Ambiguous result accepted.');
            } catch (RuntimeException) {
                self::assertCount(2, $calls);
            }
        }
    }

    public function testSqlTemplatesAreClosedAndKeepPaginationBeforeEnrichment(): void
    {
        foreach ([false, true] as $branch) {
            foreach ([false, true] as $start) {
                foreach ([false, true] as $end) {
                    $sql = Sql::equipmentHistory($branch, $start, $end);
                    self::assertTrue(Sql::allows($sql));
                    self::assertFalse(Sql::allows($sql . '; DELETE FROM STJ010'));
                    self::assertStringNotContainsString('SELECT *', $sql);
                    self::assertStringNotContainsString('STL010', $sql);
                    self::assertStringNotContainsString('RTRIM(j.TJ_CODBEM)', $sql);
                    self::assertMatchesRegularExpression(
                        '/COALESCE\(\s*'
                        . "TRY_CONVERT\\(date, NULLIF\\(j\\.TJ_DTMRFIM, ''\\), 112\\),\\s*"
                        . "TRY_CONVERT\\(date, NULLIF\\(j\\.TJ_DTMRINI, ''\\), 112\\),\\s*"
                        . "TRY_CONVERT\\(date, NULLIF\\(j\\.TJ_DTORIGI, ''\\), 112\\)\\s*"
                        . '\) AS reference_date/',
                        $sql,
                    );
                    self::assertStringContainsString('ORDER BY p.reference_date DESC, p.R_E_C_N_O_ DESC', $sql);
                    self::assertStringContainsString('j.TJ_DTMRINI, j.TJ_HOMRINI, j.TJ_DTMRFIM, j.TJ_HOMRFIM', $sql);
                    self::assertStringContainsString('j.TJ_DTMPINI, j.TJ_HOMPINI, j.TJ_DTMPFIM, j.TJ_HOMPFIM', $sql);
                    self::assertStringContainsString('j.TJ_TIPO, j.TJ_CODAREA, j.TJ_CCUSTO, j.TJ_SITUACA, j.TJ_TERMINO', $sql);
                    self::assertStringContainsString('ORDER BY reference_date DESC, j.R_E_C_N_O_ DESC', $sql);
                    self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY', $sql);
                    self::assertSame(6, substr_count($sql, "D_E_L_E_T_ <> '*'"));
                    // Correlation belongs in WHERE, never inside MAX/COUNT expressions.
                    self::assertSame(4, substr_count($sql, 'OUTER APPLY'));
                    self::assertSame(2, substr_count($sql, 'SELECT COUNT(*) AS matches, MAX(b.T9_NOME) AS name'));
                    self::assertSame(2, substr_count($sql, 'SELECT COUNT(*) AS matches, MAX(s.T4_NOME) AS name'));
                    self::assertDoesNotMatchRegularExpression('/(?:MAX|MIN|COUNT)\([^)]*j\./i', $sql);
                    self::assertStringContainsString('b.T9_FILIAL = j.TJ_FILIAL', $sql);
                    self::assertStringContainsString("b.T9_FILIAL = ''", $sql);
                    self::assertStringContainsString('s.T4_FILIAL = j.TJ_FILIAL', $sql);
                    self::assertStringContainsString("s.T4_FILIAL = ''", $sql);
                    self::assertLessThan(strpos($sql, 'OUTER APPLY'), strpos($sql, 'FETCH NEXT'));
                }
            }
        }
        self::assertTrue(Sql::allows(Sql::HISTORY_USER_COLUMNS));
        $this->expectException(LogicException::class);
        (new ProtheusReadOnly())->prepare(Sql::equipmentHistory(true) . '; SELECT 2');
    }

    public function testCommandOptionsAndInvalidInputWithoutDatabase(): void
    {
        $command = new ProtheusHealthCommand();
        $parser = $command->buildOptionParser(new ConsoleOptionParser('protheus_health'));
        [$options] = $parser->parse(['--bem', 'MEL 80 115', '--filial', '01', '--pagina', '2', '--limite', '10']);
        self::assertSame('MEL 80 115', $options['bem']);
        self::assertSame('01', $options['filial']);
        $io = $this->createMock(ConsoleIo::class);
        $io->expects(self::exactly(2))->method('error');
        self::assertSame(1, $command->execute(new Arguments([], $options + ['os' => '004368'], []), $io));
        $options['limite'] = '101';
        self::assertSame(1, $command->execute(new Arguments([], $options, []), $io));
    }

    private function row(string $number): array
    {
        return [
            'TJ_FILIAL' => '01 ', 'TJ_ORDEM' => $number, 'TJ_CODBEM' => 'MEL 80 115 ',
            'equipment_name' => 'MOTOR ROSCA RO-02 - SILO 01 ', 'equipment_matches' => 1,
            'service_name' => 'PREVENTIVA ELETRICA ', 'service_matches' => 1, 'identity_count' => 1,
            'TJ_SITUACA' => 'X', 'descricao' => 'TROCA DOS ROLAMENTOS DO MOTOR',
        ];
    }

    private function repository(array $rows, array &$calls, array $columns = ['inicio' => null, 'fim' => null]): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use ($rows, &$calls, $columns) {
            $calls[] = [$sql, $params, $types];
            self::assertTrue(Sql::allows($sql));
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($sql === Sql::HISTORY_USER_COLUMNS ? [$columns] : $rows);
            $statement->expects(self::once())->method('closeCursor');

            return $statement;
        });

        return new ProtheusRepository($connection);
    }
}
