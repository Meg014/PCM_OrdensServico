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
        self::assertCount(2, $calls);
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
        self::assertCount(2, $calls);
        self::assertStringContainsString('GROUP BY TJ_FILIAL, TJ_CODAREA, TJ_SERVICO, TJ_TIPO', $calls[0][0]);
    }

    public function testClosedSqlAggregatesAndPreservesStatusAndDateScope(): void
    {
        $sql = ProtheusQueries::MANAGEMENT;
        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        foreach (['TOP (2001)', "TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'", '>= CONVERT(date, :cutoff, 112)',
            "TJ_SITUACA = 'L' AND TJ_TERMINO = 'S'", "NULLIF(TJ_DTMPINI, '')", 'GROUP BY TJ_FILIAL, TJ_CODAREA, TJ_SERVICO',
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
            self::assertSame(array_fill(0, count(ProtheusDashboardService::CARDS), null), array_values($result['indicators']));
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

    public function testGeneralAnalysisUsesOneHistoricalAggregateAndFriendlyLabels(): void
    {
        $calls = [];
        $analysisRows = [
            ['dimension' => 'total', 'code' => '', 'branch' => '', 'quantity' => 25,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
            ['dimension' => 'equipment', 'code' => 'FAB 80 080 ', 'branch' => '01', 'quantity' => 37,
                'identity_count' => 1, 'equipment_name' => 'EXPANDER EX-245 ', 'equipment_matches' => 1],
            ['dimension' => 'cost_center', 'code' => 'CC100', 'branch' => '01', 'quantity' => 25,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
            ['dimension' => 'service', 'code' => 'COREME ', 'branch' => '01', 'quantity' => 19,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0,
                'service_name' => 'CORRETIVA EMERGENCIAL ', 'service_matches' => 1],
            ['dimension' => 'type', 'code' => 'COR', 'branch' => '01', 'quantity' => 25,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
            ['dimension' => 'area', 'code' => 'MECANI', 'branch' => '01', 'quantity' => 25,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
            ['dimension' => 'status', 'code' => 'completed', 'branch' => '', 'quantity' => 12,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
            ['dimension' => 'status', 'code' => 'open', 'branch' => '', 'quantity' => 9,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
            ['dimension' => 'status', 'code' => 'canceled', 'branch' => '', 'quantity' => 4,
                'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0],
        ];
        $result = (new ProtheusDashboardService($this->repository([], $calls, $analysisRows)))->load(['area' => 'MECANI']);
        self::assertTrue($result['available']);
        self::assertSame(25, $result['analysis']['total']);
        self::assertSame(['code' => 'FAB 80 080', 'name' => 'EXPANDER EX-245', 'branch' => '01', 'quantity' => 37],
            $result['analysis']['equipment'][0]);
        self::assertSame('Corretiva', $result['analysis']['maintenance'][0]['label']);
        self::assertSame(['code' => 'COREME', 'name' => 'CORRETIVA EMERGENCIAL', 'branch' => '01', 'quantity' => 19],
            $result['analysis']['services'][0]);
        self::assertSame('Mecânica', $result['analysis']['sectors'][0]['label']);
        self::assertSame(['completed' => 12, 'open' => 9, 'canceled' => 4], $result['analysis']['status']);
        self::assertCount(2, $calls);
        self::assertSame('MECANI', $calls[1][1]['area']);
        foreach (["j.D_E_L_E_T_ <> '*'", 'COUNT_BIG(*)', 'GROUPING SETS', 'ROW_NUMBER()', 'ST9010', 'ST4010'] as $sql) {
            self::assertStringContainsString($sql, $calls[1][0]);
        }
        foreach ([\App\Service\Protheus\ProtheusOperationalEligibility::OPEN,
            \App\Service\Protheus\ProtheusOperationalEligibility::CLOSED,
            'TJ_DTMPINI'] as $operationalRule) self::assertStringNotContainsString($operationalRule, $calls[1][0]);
    }

    public function testPresentationDoesNotQueryOrReturnDetailedAnalysis(): void
    {
        $calls = [];
        $result = (new ProtheusDashboardService($this->repository([], $calls)))->load([], false);
        self::assertTrue($result['available']);
        self::assertNull($result['analysis']);
        self::assertCount(1, $calls);
        self::assertSame(ProtheusQueries::MANAGEMENT, $calls[0][0]);
    }

    public function testPresentationOmitsBlankAreasAndUsesFriendlyNames(): void
    {
        $calls = [];
        $rows = [];
        foreach (['MECANI', 'ELETRI', 'CALDEI', 'INSTRU', 'USINAG', 'DESTIL', 'OUTRA', '', '   '] as $area) {
            $row = $this->row('ELEPRE', 'PREVENTIVA', 1, 0);
            $row['TJ_CODAREA'] = $area;
            $rows[] = $row;
        }
        $nullArea = $this->row('ELEPRE', 'PREVENTIVA', 1, 0);
        $nullArea['TJ_CODAREA'] = null;
        $rows[] = $nullArea;

        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load([], false);

        self::assertTrue($result['available']);
        self::assertSame(10, $result['indicators']['safra_open']);
        self::assertSame(
            [
                'general', 'area:ELETRI', 'area:MECANI', 'area:CALDEI',
                'area:USINAG', 'area:INSTRU', 'area:DESTIL', 'area:OUTRA',
            ],
            array_column($result['screens'], 'key'),
        );
        self::assertSame(
            [
                'PCM - VISÃO GERAL', 'PCM - ELÉTRICA', 'PCM - MECÂNICA', 'PCM - CALDEIRARIA',
                'PCM - USINAGEM', 'PCM - INSTRUMENTAÇÃO', 'PCM - DESTILARIA', 'PCM - OUTRA',
            ],
            array_column($result['screens'], 'title'),
        );
        self::assertNotContains('PCM - ÁREA EM BRANCO', array_column($result['screens'], 'title'));
    }

    public function testInconsistentHistoricalDistributionsAreRejected(): void
    {
        $calls = [];
        $incomplete = [['dimension' => 'total', 'code' => '', 'branch' => '', 'quantity' => 1,
            'identity_count' => 1, 'equipment_name' => null, 'equipment_matches' => 0]];
        $result = (new ProtheusDashboardService($this->repository([], $calls, $incomplete)))->load();
        self::assertFalse($result['available']);
        self::assertNull($result['analysis']);
    }

    private function row(string $code, string $name, int $open, int $closed): array
    {
        return ['TJ_FILIAL' => '01', 'TJ_CODAREA' => 'ELETRI', 'TJ_SERVICO' => $code, 'TJ_TIPO' => '',
            'service_name' => $name, 'quantity' => $open + $closed, 'open_count' => $open, 'closed_count' => $closed,
            'identity_count' => 1, 'service_matches' => 1, 'unconfirmed_count' => 0];
    }

    public function testOpportunityCountsOnlyEligibleOpenServiceCodes(): void
    {
        $calls = [];
        $rows = [$this->row('MECOPO ', 'OUTRO NOME', 14, 50),
            $this->row('ELECOP', 'OUTRO NOME', 1, 60),
            $this->row('MECOP', 'CÓDIGO ANTIGO', 10, 0),
            $this->row('X', 'PARADAS POR OPORTUNIDADE', 9, 0),
            $this->row('MECOPO', 'CANCELADA OU FORA DO CORTE', 0, 0)];
        $result = (new ProtheusDashboardService($this->repository($rows, $calls)))->load();
        self::assertTrue($result['available']);
        self::assertSame(15, $result['indicators']['opportunity']);
        self::assertSame(15, $result['screens'][1]['opportunity']);
        self::assertCount(2, $calls);
    }

    private function repository(?array $rows, array &$calls, array $analysisRows = []): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use ($rows, $analysisRows, &$calls) {
            $calls[] = [$sql, $params, $types];
            if ($rows === null) throw new RuntimeException('SQLSTATE host user password');
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($sql === ProtheusQueries::DASHBOARD ? $analysisRows : $rows);
            $statement->expects(self::once())->method('closeCursor');
            return $statement;
        });
        return new ProtheusRepository($connection);
    }
}
