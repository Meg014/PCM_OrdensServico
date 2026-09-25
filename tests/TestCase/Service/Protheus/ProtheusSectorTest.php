<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use App\Service\Protheus\ProtheusSectorQueries;
use App\Service\Protheus\ProtheusSectorService;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class ProtheusSectorTest extends TestCase
{
    public function testBacklogUsesOpenScopeOriginAndReusesPaginatedTable(): void
    {
        $calls = [];
        $sector = (new ProtheusSectorService($this->repository($calls)))->load('ELETRI',
            ['backlog_age' => '0_7', 'area' => 'MECANI', 'page' => 2, 'limit' => 1]);
        self::assertTrue($sector['available']);
        self::assertSame(2, $sector['backlog']['total']);
        self::assertSame(2, $sector['backlog']['ages']['0_7']);
        self::assertCount(3, $calls);
        foreach ([1, 2] as $index) {
            self::assertSame('ELETRI', $calls[$index][1]['area']);
            self::assertArrayNotHasKey('cutoff', $calls[$index][1]);
            self::assertStringContainsString("TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'", $calls[$index][0]);
            self::assertStringNotContainsString(':cutoff', $calls[$index][0]);
            self::assertStringContainsString('DATEDIFF(day, origin_date, CONVERT(date, :as_of, 23))', $calls[$index][0]);
            self::assertStringContainsString('TRY_CONVERT(date, j.TJ_DTORIGI, 112)', $calls[$index][0]);
            self::assertStringNotContainsString('STL010', $calls[$index][0]);
            self::assertTrue(ProtheusQueries::allows(ProtheusQueries::withAreaScope($calls[$index][0])));
            self::assertFalse(ProtheusQueries::allows($calls[$index][0] . '; DELETE FROM STJ010'));
        }
        self::assertSame($calls[1][1]['as_of'], $calls[2][1]['as_of']);
        self::assertSame('0_7', $calls[2][1]['backlog_bucket']);
        self::assertSame(1, $calls[2][1]['offset']);
        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY', $calls[2][0]);
        $this->expectException(\InvalidArgumentException::class);
        (new ProtheusSectorService())->load('ELETRI', ['backlog_age' => 'anything']);
    }

    public function testBacklogAgeBoundariesAndSectorOpenPredicateOffline(): void
    {
        // Execute the actual CASE and centralized predicate using an in-memory SQL engine.
        $db = new \PDO('sqlite::memory:');
        preg_match('/CASE WHEN age_days IS NULL.*?END/s', ProtheusSectorQueries::backlog(), $match);
        $statement = $db->prepare('SELECT ' . $match[0] . ' FROM (SELECT CAST(:age AS INTEGER) AS age_days)');
        foreach ([[null, 'unknown'], [-1, 'future'], [0, '0_7'], [7, '0_7'], [8, '8_15'], [15, '8_15'],
            [16, '16_30'], [30, '16_30'], [31, '31_60'], [60, '31_60'], [61, 'over_60']] as [$age, $bucket]) {
            $statement->execute(['age' => $age]);
            self::assertSame($bucket, $statement->fetchColumn());
        }
        $db->exec('CREATE TABLE orders (TJ_CODAREA TEXT, TJ_SITUACA TEXT, TJ_TERMINO TEXT, D_E_L_E_T_ TEXT)');
        $db->exec("INSERT INTO orders VALUES ('ELETRI','L','N',''), ('ELETRI','L','S',''), ('ELETRI','P','N',''), ('ELETRI','C','N',''), ('MECANI','L','N',''), ('ELETRI','L','N','*')");
        $sql = 'SELECT COUNT(*) FROM orders WHERE ' . \App\Service\Protheus\ProtheusOperationalEligibility::OPEN . " AND TJ_CODAREA = :area AND D_E_L_E_T_ <> '*'";
        $statement = $db->prepare($sql);
        $statement->execute(['area' => 'ELETRI']);
        self::assertSame(1, (int)$statement->fetchColumn());
    }

    public function testScopedAggregatesPaginationAndTemplateWithoutDatabase(): void
    {
        $calls = [];
        $sector = (new ProtheusSectorService($this->repository($calls)))->load('ELETRI',
            ['q' => "004368%';--", 'filial' => '01', 'status' => 'FECHADA', 'page' => '2', 'limit' => '1', 'date_start' => '2026-01-01']);
        self::assertTrue($sector['available']);
        self::assertCount(3, $calls);
        self::assertSame('ELETRI', $calls[0][1]['area']);
        self::assertSame("%004368~%';--%", $calls[0][1]['q']);
        self::assertArrayNotHasKey('date_start', $calls[0][1]);
        self::assertSame('2026-01-01', $calls[2][1]['date_start']);
        self::assertSame(1, $calls[2][1]['offset']);
        self::assertSame('integer', $calls[2][2]['fetch']);
        self::assertTrue($sector['has_more']);
        self::assertSame(1, $sector['cards']['safra_completed']);
        self::assertSame(0, $sector['cards']['corrective']);
        self::assertFalse(ProtheusQueries::allows(ProtheusSectorQueries::page() . '; SELECT 2'));
        self::assertStringContainsString("TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'", $calls[0][0]);
        self::assertStringContainsString('ORDER BY planned_date DESC, record_id DESC', $calls[2][0]);

        if (!defined('ROOT')) require dirname(__DIR__, 4) . '/config/paths.php';
        require_once CAKE . 'Core/functions_global.php';
        \Cake\Core\Configure::write('App.namespace', 'App');
        \Cake\Core\Configure::write('App.encoding', 'UTF-8');
        \Cake\Core\Configure::write('App.paths.templates', [ROOT . '/templates/']);
        if (!\Cake\Cache\Cache::getConfig('_cake_translations_')) {
            \Cake\Cache\Cache::setConfig('_cake_translations_', ['className' => \Cake\Cache\Engine\NullEngine::class]);
        }
        \Cake\Routing\Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(\Cake\Routing\Router::createRouteBuilder('/'));
        $view = new \Cake\View\View(new \Cake\Http\ServerRequest(['url' => '/pcm/setor/ELETRI']));
        $view->setTemplatePath('Pcm');
        $view->set('sector', $sector);
        $html = $view->render('sector_protheus', false);
        self::assertStringContainsString('/pcm/protheus/os/004368?filial=01', $html);
        self::assertStringContainsString('data-sector-chart="equipment"', $html);
        self::assertStringContainsString('Fonte: Protheus', $html);
        self::assertStringContainsString('Total operacional', $html);
        self::assertStringContainsString('Paradas por Oportunidade', $html);
        self::assertStringContainsString('card=corrective', $html);
        self::assertStringContainsString('card_status=FECHADA', $html);
        self::assertStringContainsString('pcm-sector-table-scroll', $html);
        self::assertStringContainsString('Exportar apontamentos', $html);
        self::assertStringContainsString('/pcm/setor/ELETRI/apontamentos/excel', $html);
        self::assertStringNotContainsString('<script>unsafe</script>', $html);
    }

    public function testFailureDoesNotPublishPartialAggregates(): void
    {
        $calls = [];
        $diagnostic = [];
        $result = (new ProtheusSectorService($this->repository($calls, true),
            static function (\Throwable $exception, string $stage) use (&$diagnostic): void {
                $diagnostic = [$stage, $exception->getMessage()];
            }))->load('MECANI', []);
        self::assertSame(['SQL: ProtheusSectorQueries::backlog()', 'SQLSTATE private server'], $diagnostic);
        self::assertFalse($result['available']);
        self::assertSame([], $result['cards']);
        self::assertSame([], $result['orders']);
        self::assertNull($result['queried_at']);
        self::assertStringNotContainsString('SQLSTATE', json_encode($result));
    }

    public function testManagementBreakdownDoesNotDoubleCountAndCardFiltersOnlyThePage(): void
    {
        $base = ['identity_count' => 1, 'equipment_matches' => 1, 'service_matches' => 1, 'missing_start' => 0];
        $aggregate = [['dimension' => 'total', 'quantity' => 10] + $base];
        foreach ([['COR', 'MECOPO', 'EM ABERTO', 3], ['COR', 'MECOPO', 'FECHADA', 2],
            ['PRE', 'PRE01', 'EM ABERTO', 1], ['PRE', 'PRE01', 'FECHADA', 1],
            ['MEL', 'COREME', 'EM ABERTO', 2], ['COR', 'CORPRO', 'EM ABERTO', 1]] as [$type, $service, $status, $count]) {
            $aggregate[] = ['dimension' => 'cards', 'quantity' => $count, 'TJ_TIPO' => $type, 'TJ_SERVICO' => $service,
                'service_name' => 'SERVICO', 'status' => $status] + $base;
        }
        $calls = [];
        $result = (new ProtheusSectorService($this->repository($calls, false, $aggregate)))->load('MECANI',
            ['card' => 'opportunity', 'card_status' => 'EM ABERTO', 'equipment' => 'BEM01', 'page' => 2, 'limit' => 1]);
        self::assertTrue($result['available']);
        self::assertSame(['total' => 10, 'open' => 7, 'closed' => 3], $result['operational']);
        self::assertSame(['open' => 4, 'closed' => 2], $result['breakdown']['corrective']);
        self::assertSame(['open' => 3, 'closed' => 2], $result['breakdown']['opportunity']);
        self::assertSame(['open' => 2, 'closed' => 0], $result['breakdown']['emergency']);
        self::assertArrayNotHasKey('card_status', $calls[0][1]);
        self::assertSame('EM ABERTO', $calls[2][1]['card_status']);
        self::assertSame('MECOPO', $calls[2][1]['card_service1']);
        self::assertSame('ELECOP', $calls[2][1]['card_service2']);
        self::assertSame('BEM01', $calls[2][1]['equipment']);
        self::assertSame(1, $calls[2][1]['offset']);
        self::assertStringContainsString('filtered.status = card.status', $calls[2][0]);

        $calls = [];
        (new ProtheusSectorService($this->repository($calls)))->load('ELETRI', ['card' => 'corrective', 'card_status' => 'FECHADA']);
        self::assertSame('COR', $calls[2][1]['card_type']);
        self::assertSame('FECHADA', $calls[2][1]['card_status']);
        $calls = [];
        (new ProtheusSectorService($this->repository($calls)))->load('ELETRI', ['card' => 'safra']);
        self::assertSame([['code' => 'ELEPRE', 'name' => 'PREVENTIVA ELETRICA']], json_decode($calls[2][1]['season_services'], true));
    }

    public function testBacklogZeroAndAbsentDimensionsAreValid(): void
    {
        foreach ([
            [['dimension' => 'total', 'quantity' => '0']],
            [['dimension' => 'total', 'quantity' => '0'], ['dimension' => 'age', 'age_bucket' => '0_7', 'quantity' => '0']],
        ] as $backlog) {
            $calls = [];
            $result = (new ProtheusSectorService($this->repository($calls, backlog: $backlog)))->load('INSTRU', []);
            self::assertTrue($result['available']);
            self::assertSame(0, $result['backlog']['total']);
            self::assertSame(0, array_sum($result['backlog']['ages']));
            self::assertSame([], $result['backlog']['equipment']);
            self::assertSame([], $result['backlog']['costCenters']);
            self::assertSame([], $result['backlog']['maintenance']);
        }
    }

    public function testBacklogSqlEmitsExactlyOneScalarTotalForEmptyAndPopulatedInput(): void
    {
        $sql = ProtheusSectorQueries::backlog();
        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertStringNotContainsString('GROUPING SETS ((),', $sql);
        self::assertSame(1, preg_match("/SELECT 'total', NULL, NULL, NULL, NULL, NULL, NULL,.*?FROM bucketed/s", $sql, $match));
        self::assertStringNotContainsString('GROUP BY', $match[0]);
        // Execute the actual scalar branch offline; SQLite's COUNT is SQL Server's COUNT_BIG here.
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE bucketed (identity_count INTEGER, equipment_matches INTEGER, service_matches INTEGER)');
        $scalarSql = str_replace('COUNT_BIG(*)', 'COUNT(*)', $match[0]);
        foreach ([0, 3] as $expected) {
            if ($expected > 0) $db->exec('INSERT INTO bucketed VALUES (1,1,1), (1,1,1), (1,1,1)');
            $rows = $db->query($scalarSql)->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(1, $rows);
            self::assertSame($expected, (int)$rows[0]['quantity']);
        }
    }

    public function testBacklogPartialAgeDimensionsIncludingInvalidAndFutureCloseWithTotal(): void
    {
        $calls = [];
        $backlog = [['dimension' => 'total', 'quantity' => '6'],
            ['dimension' => 'age', 'age_bucket' => 'over_60', 'quantity' => '3'],
            ['dimension' => 'age', 'age_bucket' => 'unknown', 'quantity' => '2'],
            ['dimension' => 'age', 'age_bucket' => 'future', 'quantity' => '1']];
        $result = (new ProtheusSectorService($this->repository($calls, backlog: $backlog)))->load('ELETRI', []);
        self::assertTrue($result['available']);
        self::assertSame(6, $result['backlog']['total']);
        self::assertSame(['0_7' => 0, '8_15' => 0, '16_30' => 0, '31_60' => 0,
            'over_60' => 3, 'unknown' => 2, 'future' => 1], $result['backlog']['ages']);
    }

    public function testBacklogMissingTotalOrDifferentAgeSumRemainsUnavailable(): void
    {
        foreach ([[], [['dimension' => 'age', 'age_bucket' => '0_7', 'quantity' => '0']],
            [['dimension' => 'total', 'quantity' => '2'], ['dimension' => 'age', 'age_bucket' => '0_7', 'quantity' => '1']]] as $backlog) {
            $calls = [];
            $result = (new ProtheusSectorService($this->repository($calls, backlog: $backlog)))->load('USINAG', []);
            self::assertFalse($result['available']);
            self::assertArrayNotHasKey('backlog', $result);
        }
    }

    public function testExportUsesExactlyTheScreenFiltersAndIgnoresVisualPagination(): void
    {
        $combined = ['status' => 'EM ABERTO', 'equipment' => '0001', 'service' => '001',
            'service_name' => 'SERVICO', 'cost_center' => '0002', 'maintenance_type' => 'COR',
            'filial' => '01', 'q' => 'motor%_', 'date_start' => '2026-01-01', 'date_end' => '2026-09-25',
            'card' => 'opportunity', 'card_status' => 'EM ABERTO', 'backlog_age' => '0_7'];
        $cases = [[], $combined];
        foreach ($combined as $key => $value) $cases[] = [$key => $value];
        foreach ($cases as $filters) {
            $screenCalls = $exportCalls = [];
            $screen = (new ProtheusSectorService($this->repository($screenCalls)))->load('ELETRI', $filters + ['page' => 2, 'limit' => 1]);
            $export = (new ProtheusSectorService($this->repository($exportCalls)))->load('ELETRI', $filters + ['page' => 2, 'limit' => 1, 'area' => 'MECANI'], true);
            self::assertTrue($export['available']);
            self::assertSame($screen['filters'], $export['filters']);
            self::assertSame(0, $exportCalls[2][1]['offset']);
            self::assertSame(1001, $exportCalls[2][1]['fetch']);
            self::assertCount(2, $export['orders']);
            foreach ($screenCalls as $index => $call) {
                self::assertSame($call[0], $exportCalls[$index][0]);
                self::assertSame(array_diff_key($call[1], array_flip(['offset', 'fetch'])),
                    array_diff_key($exportCalls[$index][1], array_flip(['offset', 'fetch'])));
                self::assertSame('ELETRI', $exportCalls[$index][1]['area']);
            }
        }
        $calls = [];
        self::assertFalse((new ProtheusSectorService($this->repository($calls, true)))->load('ELETRI', [], true)['available']);
    }

    private function repository(array &$calls, bool $fail = false, ?array $aggregate = null, ?array $backlog = null): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use (&$calls, $fail, $aggregate, $backlog) {
            $calls[] = [$sql, $params, $types];
            self::assertTrue(ProtheusQueries::allows($sql));
            if ($fail && count($calls) === 2) throw new \RuntimeException('SQLSTATE private server');
            $base = ['identity_count' => 1, 'equipment_matches' => 1, 'service_matches' => 1, 'quantity' => 1, 'missing_start' => 0];
            $order = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '004368', 'TJ_CODBEM' => 'MEL 80 115', 'equipment_name' => '<script>unsafe</script>',
                'TJ_SERVICO' => 'ELEPRE', 'service_name' => 'PREVENTIVA ELETRICA', 'TJ_TIPO' => 'COR', 'TJ_CCUSTO' => '',
                'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'S', 'status' => 'FECHADA', 'planned_date' => '2026-01-01',
                'TJ_HOMPINI' => '', 'TJ_DTPRINI' => '', 'TJ_HOPRINI' => ''] + $base;
            $rows = count($calls) === 1 ? [['dimension' => 'total'] + $base, ['dimension' => 'cards'] + $order] : [$order, $order];
            if ($sql === ProtheusSectorQueries::backlog()) $rows = [['dimension' => 'total', 'quantity' => 2] + $base, ['dimension' => 'age', 'age_bucket' => '0_7', 'quantity' => 2] + $base];
            if ($sql === ProtheusSectorQueries::backlog() && $backlog !== null) {
                $rows = array_map(static fn (array $row): array => $row + $base, $backlog);
            }
            if (count($calls) === 1 && $aggregate !== null) $rows = $aggregate;
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->expects(self::once())->method('closeCursor');
            return $statement;
        });
        return new ProtheusRepository($connection);
    }
}
