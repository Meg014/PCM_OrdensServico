<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ProtheusDashboardService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use App\Service\PcmServiceClassifier;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProtheusDashboardTest extends TestCase
{
    public function testSeasonalCardsReuseLegacyClassifierWithoutInventingType(): void
    {
        $calls = [];
        $rows = [$this->row('ELEPRE', 'PREVENTIVA ELETRICA', 2, 3),
            $this->row('X', 'Manutenção ENTRESSAFRA', 4, 5),
            $this->row('COREME', 'ENTRESSAFRA', 1, 0),
            $this->row('CORPRO', 'ENTRESSAFRA', 1, 0)];
        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load(['filial' => '01', 'bem' => "X'; DELETE--"]);
        self::assertTrue($result['available']);
        self::assertSame(4, $result['indicators']['safra_open']);
        self::assertSame(3, $result['indicators']['safra_completed']);
        self::assertSame(4, $result['indicators']['offseason_open']);
        self::assertSame(5, $result['indicators']['offseason_completed']);
        foreach (['preventive', 'corrective', 'improvement'] as $key) self::assertSame(0, $result['indicators'][$key]);
        foreach (['emergency', 'scheduled'] as $key) self::assertSame(1, $result['indicators'][$key]);
        self::assertCount(2, $result['screens']);
        self::assertCount(1, $calls);
        self::assertSame("X'; DELETE--", $calls[0][1]['bem']);
        self::assertSame('20260101', $calls[0][1]['cutoff']);
        self::assertStringNotContainsString("X'; DELETE--", $calls[0][0]);
        self::assertSame('string', $calls[0][2]['filial']);
    }

    public function testSeasonSplitIsIndependentOfUnknownMaintenanceType(): void
    {
        $classifier = new PcmServiceClassifier();
        foreach ([['COREME', 'ENTRESSAFRA'], ['CORPRO', 'ENTRESSAFRA'], ['X', 'CORRETIVA EMERGENCIAL ENTRESSAFRA'],
            ['X', 'CORRETIVA PROGRAMADA ENTRESSAFRA'], ['X', 'manutenção entressafra'], ['ELEPRE', 'PREVENTIVA ELETRICA']] as [$code, $name]) {
            foreach ([null, '', 'COR', 'PRE', 'MEL', 'UNKNOWN'] as $type) {
                self::assertSame($classifier->classifySnapshot($type, $code, $name) === 'ENTRESSAFRA',
                    $classifier->classify($code, $name) === 'ENTRESSAFRA');
            }
        }
    }

    public function testTypesUseOnlyOrderCodeAndEligibleOpenCounts(): void
    {
        $calls = [];
        $rows = [];
        foreach ([['COR ', 'ELEPRE', 'PREVENTIVA ELETRICA', 2, 5],
            ['COR', 'COROPE', 'CORRETIVA OPERACIONAL', 3, 7],
            ['PRE', 'CORPRO', 'CORRETIVA PROGRAMADA', 4, 1],
            ['MEL', 'COREME', 'CORRETIVA EMERGENCIAL', 6, 2],
            ['', 'PRE', 'PREVENTIVA', 8, 0], ['XYZ', 'COR', 'CORRETIVA', 9, 0],
            ['COR', 'X', 'CANCELADA OU FORA DO CORTE', 0, 0]] as [$type, $code, $name, $open, $closed]) {
            $row = $this->row($code, $name, $open, $closed);
            $row['TJ_TIPO'] = $type;
            $rows[] = $row;
        }
        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load();
        self::assertTrue($result['available']);
        foreach ([$result['indicators'], $result['screens'][1]] as $counts) {
            self::assertSame(5, $counts['corrective']);
            self::assertSame(4, $counts['preventive']);
            self::assertSame(6, $counts['improvement']);
            self::assertSame(6, $counts['emergency']);
            self::assertSame(4, $counts['scheduled']);
        }
        self::assertCount(1, $calls);
        self::assertStringContainsString('GROUP BY TJ_FILIAL, TJ_CODAREA, TJ_SERVICO, TJ_TIPO', $calls[0][0]);
    }

    public function testClosedSqlAggregatesAndPreservesStatusAndDateScope(): void
    {
        $sql = ProtheusQueries::MANAGEMENT;
        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        foreach (['TOP (2001)', "TJ_TERMINO = 'N' AND TJ_SITUACA <> 'C'", 'planned_start >= CONVERT(date, :cutoff, 112)',
            "TJ_TERMINO = 'S' AND TJ_SITUACA <> 'C'", "NULLIF(j.TJ_DTMPINI, '')", 'GROUP BY TJ_FILIAL, TJ_CODAREA, TJ_SERVICO',
            's.T4_FILIAL = c.TJ_FILIAL', "s.T4_FILIAL = ''"] as $expected) self::assertStringContainsString($expected, $sql);
        self::assertSame(3, substr_count($sql, "D_E_L_E_T_ <> '*'"));
        self::assertStringNotContainsString('STL010', $sql);
        self::assertStringNotContainsString('SELECT *', $sql);
    }

    public function testEmergencyAndScheduledNeverInferFromNames(): void
    {
        $calls = [];
        $rows = [$this->row('COREME ', 'OUTRO NOME', 2, 20),
            $this->row('CORPRO ', 'OUTRO NOME', 3, 30),
            $this->row('X', 'MANUTENCAO CORRETIVA EMERGENCIAL', 7, 0),
            $this->row('Y', 'MANUTENCAO CORRETIVA PROGRAMADA', 8, 0),
            $this->row('COREME', 'FORA DO ESCOPO DE ABERTAS', 0, 9)];
        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load();
        self::assertTrue($result['available']);
        self::assertSame(2, $result['indicators']['emergency']);
        self::assertSame(3, $result['indicators']['scheduled']);
        self::assertSame(20, $result['indicators']['safra_open']);
    }

    public function testUnconfirmedOrAmbiguousDataAndFailureNeverBecomeZero(): void
    {
        foreach ([null, 'identity_count', 'service_matches', 'unconfirmed_count', 'service_name'] as $problem) {
            $row = $this->row('X', 'ENTRESSAFRA', 1, 0);
            if ($problem !== null) $row[$problem] = $problem === 'service_name' ? null : 2;
            $calls = [];
            $result = (new ProtheusDashboardService($this->repository($problem === null ? null : [$row], $calls)))->load();
            self::assertFalse($result['available']);
            self::assertNull($result['record_count']);
            self::assertNull($result['queried_at']);
            self::assertSame([], $result['screens']);
            self::assertSame(array_fill(0, 9, null), array_values($result['indicators']));
            self::assertStringNotContainsString('SQLSTATE', json_encode($result));
        }
    }

    public function testEmptyAndTruncatedAggregateAreDifferent(): void
    {
        $calls = [];
        $result = (new ProtheusDashboardService($this->repository([], $calls)))->load();
        self::assertTrue($result['available']);
        self::assertSame(0, $result['indicators']['safra_open']);
        $rows = array_fill(0, 2001, $this->row('X', 'SERVICO', 1, 0));
        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load();
        self::assertFalse($result['available']);
    }

    private function row(string $code, string $name, int $open, int $closed): array
    {
        return ['TJ_FILIAL' => '01', 'TJ_CODAREA' => 'ELETRI', 'TJ_SERVICO' => $code, 'TJ_TIPO' => '',
            'service_name' => $name, 'quantity' => $open + $closed, 'open_count' => $open, 'closed_count' => $closed,
            'identity_count' => 1, 'service_matches' => 1, 'unconfirmed_count' => 0];
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
