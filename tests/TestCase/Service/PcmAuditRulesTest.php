<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Table\MaintenanceAreasTable;
use App\Service\OrderAuditFlags;
use App\Service\PcmPeriod;
use App\Service\PcmServiceClassifier;
use App\Service\Protheus\ProtheusOperationalEligibility;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\SectorPresentationScope;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusRepository;
use App\Database\Driver\ProtheusReadOnly;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use App\Service\WorkOrderStatusResolver;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PcmAuditRulesTest extends TestCase
{
    public function testNameAloneDeterminesSeason(): void
    {
        $classifier = new PcmServiceClassifier();
        foreach (['ENTRESSAFRA', 'ENTRESSAFRA MECANICA', 'ENTRESSAFRA 24-25 ELETRICA',
            'CORRETIVA NAO PROGRAMADA ENTRESSAFRA', 'entressafra'] as $name) {
            foreach (['COREME', 'CORPRO', 'ENTRES', 'X', null] as $code) {
                foreach (['COR', 'PRE', 'MEL', null] as $type) {
                    self::assertSame('ENTRESSAFRA', $classifier->classifySnapshot($type, $code, $name, '02'));
                }
            }
        }
        foreach (['ENTRES', '2425ME', 'ESMECA'] as $code) {
            self::assertSame('OUTROS', $classifier->classify($code, 'TESTE', '01'));
        }
    }

    public function testEveryOfficialAreaAndElectricalGrouping(): void
    {
        self::assertSame(['ELETRI' => 'Elétrica/Instrumentação', 'CALD' => 'Caldeira', 'FAB' => 'Fábrica',
            'TRATA' => 'Trat. de Caldo', 'MECANI' => 'Mecânica', 'CALDEI' => 'Caldeiraria', 'USINAG' => 'Usinagem',
            'INSTRU' => 'Elétrica/Instrumentação', 'DESTIL' => 'Destilaria', 'OPERAC' => 'Operacional',
            'TERCEI' => 'Terceiro', 'LABORA' => 'Laboratório', 'MOEND' => 'Moenda'], MaintenanceAreasTable::FRIENDLY_NAMES);
        self::assertSame('ELETRI', MaintenanceAreasTable::presentationCode(' instru '));
        self::assertSame('', MaintenanceAreasTable::presentationCode(null));
        self::assertSame('OUTRA', MaintenanceAreasTable::presentationCode('OUTRA'));
    }

    public function testPeriodsAreExplicitAndSqlIsBoundAndReadOnly(): void
    {
        self::assertNull(PcmPeriod::fromFilters([]));
        self::assertSame('2024-11-01', PcmPeriod::fromFilters([
            'date_start' => '2024-11-01', 'date_end' => '2025-03-31',
        ])['date_start']);
        foreach ([ProtheusQueries::MANAGEMENT, ProtheusQueries::dashboardAnalysis(),
            ProtheusQueries::orders(false, false, false, true, true),
            ProtheusQueries::orders(false, false, false, true, true, false, true),
            ProtheusQueries::generalEntries()] as $base) {
            $sql = SectorPresentationScope::dashboardPeriod($base);
            self::assertStringContainsString(':dashboard_start', $sql);
            self::assertStringContainsString(':dashboard_end', $sql);
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
            self::assertSame($base, SectorPresentationScope::withoutDashboardPeriod($sql));
        }
    }

    public function testInvalidAndIncompletePeriodsAreRejected(): void
    {
        foreach ([['2026-02-30', '2026-03-01'], ['2026-11-17', '2025-11-17'],
            ['2026-01-01', ''], ["2026-01-01';DELETE", '2026-11-17']] as [$start, $end]) {
            try {
                PcmPeriod::fromFilters(['date_start' => $start, 'date_end' => $end]);
                self::fail('Invalid period accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCancellationWinsAndReleaseIsNotCompletion(): void
    {
        $resolver = new WorkOrderStatusResolver();
        self::assertSame('CANCELADA', $resolver->resolve('Cancelado', 'Sim'));
        self::assertSame('EM ABERTO', $resolver->resolve('Liberado', 'Não'));
        self::assertSame('FECHADA', $resolver->resolve('Liberado', 'Sim'));
        self::assertSame("TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'", ProtheusOperationalEligibility::OPEN);
        self::assertSame("TJ_TERMINO = 'S' AND (TJ_SITUACA IS NULL OR TJ_SITUACA <> 'C')", ProtheusOperationalEligibility::CLOSED);
        self::assertTrue(ProtheusOperationalEligibility::isClosed('P', 'S'));
        self::assertTrue(ProtheusOperationalEligibility::isClosed(null, 'S'));
        self::assertFalse(ProtheusOperationalEligibility::isClosed('C', 'S'));
        self::assertFalse(ProtheusOperationalEligibility::isClosed('L', 'N'));
    }

    public function testFlagsPreserveSourceAndNeverGuessArea(): void
    {
        $source = ['TJ_ORDEM' => '000123', 'TJ_CODAREA' => '', 'TJ_CCUSTO' => 'MECANI',
            'TJ_CODBEM' => 'CALD', 'service_name' => 'TESTE', 'TJ_DTORIGI' => '20261118'];
        $before = $source;
        $flags = OrderAuditFlags::forOrder($source, new DateTimeImmutable('2026-11-17'));
        self::assertSame(['missing_area', 'test_service'], array_keys($flags));
        self::assertSame($before, $source);
        self::assertSame(['unknown_area'], array_keys(OrderAuditFlags::forOrder([
            'TJ_CODAREA' => 'NOVA', 'service_name' => 'ENTRESSAFRA', 'TJ_DTORIGI' => '20261117',
        ], new DateTimeImmutable('2026-11-17'))));
    }

    public function testFuturePlanningDoesNotGenerateFlagsOrChangeSourceDates(): void
    {
        $asOf = new DateTimeImmutable('2026-11-23');
        foreach (['maintenance_planned_start', 'maintenance_planned_end', 'general_planned_start',
            'general_planned_end', 'TJ_DTMPINI', 'TJ_DTMPFIM', 'TJ_DTPPINI', 'TJ_DTPPFIM',
            'planned_date'] as $field) {
            foreach (['20261124', '2026-11-24', new DateTimeImmutable('2026-11-24')] as $date) {
                $source = ['TJ_CODAREA' => 'MECANI', $field => $date];
                $before = $source;
                self::assertSame([], OrderAuditFlags::forOrder($source, $asOf), $field);
                self::assertSame($before, $source);
            }
        }
    }

    public function testFutureOriginAndExecutionAreValidRegardlessOfToday(): void
    {
        $asOf = new DateTimeImmutable('2026-11-23');
        foreach (['origin_date', 'maintenance_actual_start', 'maintenance_actual_end',
            'general_actual_start', 'general_actual_end', 'TJ_DTORIGI',
            'TJ_DTMRINI', 'TJ_DTMRFIM', 'TJ_DTPRINI', 'TJ_DTPRFIM'] as $field) {
            foreach (['20261124', '2026-11-24', new DateTimeImmutable('2026-11-24')] as $date) {
                $source = ['TJ_CODAREA' => 'MECANI', 'TJ_DTMPINI' => '20261201', $field => $date];
                $before = $source;
                self::assertSame([], OrderAuditFlags::forOrder($source, $asOf), $field);
                self::assertSame([], OrderAuditFlags::forOrder($source, new DateTimeImmutable('2020-01-01')), $field);
                self::assertSame($before, $source);
            }
            self::assertSame([], OrderAuditFlags::forOrder([
                'TJ_CODAREA' => 'MECANI', $field => '20261123',
            ], $asOf), $field);
        }
    }

    public function testFutureReportedOrdersAreValidAndYear2502IsFlaggedWithoutChangingSource(): void
    {
        foreach (['007881' => '20261125', '007880' => '20261125', '007492' => '20261216', '007659' => '25020814'] as $number => $date) {
            $order = ['TJ_ORDEM' => $number, 'TJ_CODAREA' => 'MECANI', 'TJ_DTORIGI' => $date, 'TJ_DTMPINI' => $date];
            $before = $order;
            $flags = OrderAuditFlags::forOrder($order);
            self::assertSame($number === '007659' ? ['date_out_of_range'] : [], array_keys($flags));
            self::assertSame($before, $order);
            self::assertSame($flags, OrderAuditFlags::forOrder($order, new DateTimeImmutable('2000-01-01')));
        }
    }

    public function testCalendarAndOperationalRangeValidationIncludesPlanningAndExecution(): void
    {
        foreach (['TJ_DTORIGI', 'TJ_DTMPINI', 'TJ_DTPPFIM', 'TJ_DTMRFIM', 'general_actual_end'] as $field) {
            foreach (['20260230' => 'invalid_date', 'data inválida' => 'invalid_date',
                '18991231' => 'date_out_of_range', '25020814' => 'date_out_of_range'] as $date => $flag) {
                self::assertSame([$flag], array_keys(OrderAuditFlags::forOrder(['TJ_CODAREA' => 'MECANI', $field => (string)$date])));
            }
            foreach (['19000101', '21001231', '', '00000000', '0000-00-00', null] as $date) {
                self::assertSame([], OrderAuditFlags::forOrder(['TJ_CODAREA' => 'MECANI', $field => $date]));
            }
        }
    }

    public function testHistoricalDrilldownPreservesPlannedPeriodInPageAndCount(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params) use (&$calls) {
            self::assertTrue(ProtheusQueries::allows($sql));
            $calls[] = [$sql, $params];
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn(str_contains($sql, 'AS total') ? [['total' => 0]] : []);

            return $statement;
        });
        $result = (new OrderListingService(new ProtheusRepository($connection)))->load([
            'period_start' => '2025-11-01', 'period_end' => '2026-03-31',
        ]);
        self::assertTrue($result['available']);
        self::assertCount(2, $calls);
        foreach ($calls as [$sql, $params]) {
            self::assertStringContainsString('j.TJ_DTPRFIM', $sql);
            self::assertStringContainsString('j.TJ_DTORIGI', $sql);
            self::assertSame('2025-11-01', $params['dashboard_start']);
            self::assertSame('2026-03-31', $params['dashboard_end']);
        }
        self::assertSame(0, $result['total']);
    }

    public function testCanceledHistoryIsExplicitAndUsesMatchingPageAndCount(): void
    {
        $calls = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params) use (&$calls) {
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertStringContainsString("j.TJ_SITUACA = 'C' /* historical consultation */", $sql);
            self::assertSame('C', $params['situacao']);
            $calls++;
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn(str_contains($sql, 'AS total') ? [['total' => 0]] : []);

            return $statement;
        });
        $result = (new OrderListingService(new ProtheusRepository($connection)))->load(['situacao' => 'C']);
        self::assertTrue($result['available']);
        self::assertSame(2, $calls);
        self::assertSame(0, $result['total']);
    }

    public function testReconciliationUsesRealCompletionAndUniqueBoundParameters(): void
    {
        foreach ([\App\Service\Protheus\PcmReconciliationQueries::totals(),
            \App\Service\Protheus\PcmReconciliationQueries::QUALITY,
            \App\Service\Protheus\SectorPresentationScope::dashboardPeriod(ProtheusQueries::orders(false, false, false, true))] as $sql) {
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertFalse(ProtheusQueries::allows($sql . '; UPDATE STJ010 SET TJ_TERMINO=1'));
            preg_match_all('/:([a-z_]+)/', $sql, $matches);
            self::assertSame(count($matches[1]), count(array_unique($matches[1])), 'SQL Server binds each placeholder once.');
        }
        $date = PcmPeriod::sqlDate();
        self::assertStringContainsString('TJ_DTPRFIM', $date);
        self::assertStringContainsString('TJ_DTORIGI', $date);
        self::assertStringNotContainsString('TJ_DTMPINI', $date);
        self::assertStringNotContainsString('TJ_DTMRFIM', $date);
        self::assertStringNotContainsString('COALESCE', $date);
    }

    public function testDefaultCycleRollsYearAndCanUseAnOfficialConfiguredStart(): void
    {
        $before = \Cake\Core\Configure::read('Pcm.currentCycleStart');
        try {
            \Cake\Core\Configure::write('Pcm.currentCycleStart', null);
            $today = new DateTimeImmutable('2026-11-17 12:00:00', new \DateTimeZone('America/Sao_Paulo'));
            self::assertSame('2026-01-01', PcmPeriod::currentCycle($today)['date_start']);
            self::assertSame('2026-11-17', PcmPeriod::currentCycle($today)['date_end']);
            self::assertSame('2027-01-01', PcmPeriod::currentCycle($today->modify('+1 year'))['date_start']);
            \Cake\Core\Configure::write('Pcm.currentCycleStart', '2026-04-01');
            self::assertSame('2026-04-01', PcmPeriod::currentCycle($today)['date_start']);
            self::assertSame('configured_cycle', PcmPeriod::currentCycle($today)['source']);
        } finally {
            \Cake\Core\Configure::write('Pcm.currentCycleStart', $before);
        }
    }

    public function testDefaultSqlFiltersOnlyClosedOrdersAndNeverUsesPlanning(): void
    {
        $sql = \App\Service\Protheus\SectorPresentationScope::dashboardPeriod(ProtheusQueries::MANAGEMENT, true);
        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        self::assertSame(ProtheusQueries::MANAGEMENT, \App\Service\Protheus\SectorPresentationScope::withoutDashboardPeriod($sql));
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE sample (TJ_ORDEM TEXT,TJ_SITUACA TEXT,TJ_TERMINO TEXT,TJ_DTPRFIM TEXT,D_E_L_E_T_ TEXT)');
        $db->exec("INSERT INTO sample VALUES ('000001','L','N','2024-01-01',''),
            ('000002','L','S','2025-12-31',''),('000003','L','S','2026-01-01',''),
            ('000004','L','S','2026-11-17',''),('000005','L','S','2026-11-18',''),
            ('000006','L','S',NULL,''),('000007','C','S','2026-11-17','')");
        $query = \App\Service\Protheus\SectorPresentationScope::dashboardPeriod(
            "SELECT TJ_ORDEM FROM sample j WHERE j.D_E_L_E_T_ <> '*' AND (j.TJ_SITUACA IS NULL OR j.TJ_SITUACA <> 'C')", true,
        );
        $query = str_replace(["TRY_CONVERT(date, NULLIF(j.TJ_DTPRFIM, ''), 112)",
            'CONVERT(date, :dashboard_start, 23)', 'CONVERT(date, :dashboard_end, 23)'],
            ["NULLIF(j.TJ_DTPRFIM, '')", ':dashboard_start', ':dashboard_end'], $query);
        $statement = $db->prepare($query . ' ORDER BY TJ_ORDEM');
        $statement->execute(['dashboard_start' => '2026-01-01', 'dashboard_end' => '2026-11-17']);
        self::assertSame(['000001', '000003', '000004'], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
