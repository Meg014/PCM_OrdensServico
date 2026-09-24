<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\EquipmentHistoryService;
use App\Service\Protheus\ProtheusEquipmentQueries as Q;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class EquipmentHistoryTest extends TestCase
{
    public function testBoundQueriesPaginationAndFailureWithoutRealConnection(): void
    {
        $calls = [];
        $service = new EquipmentHistoryService($this->repository($calls));
        $result = $service->load(['bem' => 'MEL 80 115', 'filial' => '01', 'page' => 2, 'limit' => 1,
            'type' => 'COR', 'status' => 'open', 'date_start' => '2026-01-01']);
        self::assertTrue($result['available']);
        self::assertFalse($result['not_found']);
        self::assertTrue($result['has_more']);
        self::assertCount(1, $result['orders']);
        self::assertCount(3, $calls);
        self::assertSame('MEL 80 115', $calls[1][1]['bem']);
        self::assertSame('01', $calls[1][1]['filial']);
        self::assertSame('COR', $calls[1][1]['type']);
        self::assertSame('open', $calls[1][1]['status']);
        self::assertSame(1, $calls[2][1]['offset']);
        self::assertSame('integer', $calls[2][2]['fetch']);
        self::assertStringContainsString('j.R_E_C_N_O_ DESC', $calls[2][0]);
        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :fetch ROWS ONLY', $calls[2][0]);
        foreach ($calls as [$sql]) {
            self::assertStringNotContainsString('STL010', $sql);
            self::assertFalse(ProtheusQueries::allows($sql . '; SELECT 2'));
        }
        $calls = [];
        $failure = (new EquipmentHistoryService($this->repository($calls, true)))->load(['bem' => 'MEL 80 115', 'filial' => '01']);
        self::assertFalse($failure['available']);
        self::assertArrayNotHasKey('summary', $failure);
        self::assertStringNotContainsString('SQLSTATE', json_encode($failure));
    }

    public function testSummaryRulesAndRecurrenceWindowBoundariesOffline(): void
    {
        $sql = Q::summary();
        self::assertSame(1, preg_match('/\), metrics AS \(\n(.*?)\n\), context AS/s', $sql, $match));
        // Execute the actual metric expressions with equivalent SQLite COUNT/date functions.
        $metrics = str_replace('COUNT_BIG(', 'COUNT(', $match[1]);
        $metrics = preg_replace('/CONVERT\(date, (:[a-zA-Z0-9]+), 23\)/', 'date($1)', $metrics);
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE filtered (TJ_SITUACA TEXT, TJ_TERMINO TEXT, TJ_TIPO TEXT, origin_date TEXT)');
        $insert = $db->prepare('INSERT INTO filtered VALUES (?,?,?,?)');
        $today = new \DateTimeImmutable('2026-09-24');
        foreach ([0, 29, 30, 89, 90, 364, 365] as $i => $age) {
            $insert->execute(['L', in_array($age, [29, 89, 364, 365], true) ? 'S' : 'N', 'COR', $today->modify("-$age days")->format('Y-m-d')]);
        }
        foreach ([['C','N','COR','2026-09-24'], ['P','N','COR','2026-09-24'], ['L','N','PRE','2026-09-24'],
            ['L','S','MEL','2026-09-24'], ['L','N','COR','2026-09-25'], ['L','N','COR',null]] as $row) $insert->execute($row);
        $params = [];
        foreach ([30,90,365] as $days) {
            $params['since'.$days] = $today->modify('-'.($days-1).' days')->format('Y-m-d');
            $params['until'.$days] = '2026-09-24';
        }
        $statement = $db->prepare($metrics);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['total'=>13, 'open_count'=>6, 'closed_count'=>5, 'canceled_count'=>1, 'pending_count'=>1,
            'corrective'=>9, 'preventive'=>1, 'improvement'=>1, 'recurrence30'=>2, 'recurrence90'=>4, 'recurrence365'=>6], $row);
        $db->exec('DELETE FROM filtered'); // Isolated in-memory fixture, never Protheus.
        $statement->execute($params);
        self::assertSame(array_fill_keys(array_keys($row), 0), $statement->fetch(\PDO::FETCH_ASSOC));
    }

    public function testInvalidDateRejectedBeforeAnyRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EquipmentHistoryService())->load(['bem' => 'MEL 80 115', 'filial' => '01', 'date_start' => '2026-02-30']);
    }

    public function testEquipmentRouteAndEscapedPageKeepExistingOrderDetail(): void
    {
        if (!defined('ROOT')) require dirname(__DIR__, 4) . '/config/paths.php';
        require_once CAKE . 'Core/functions_global.php';
        \Cake\Core\Configure::write('App.namespace', 'App');
        \Cake\Core\Configure::write('App.encoding', 'UTF-8');
        \Cake\Core\Configure::write('App.paths.templates', [ROOT . '/templates/']);
        \Cake\Cache\Cache::setConfig('_cake_translations_', ['className' => \Cake\Cache\Engine\NullEngine::class]);
        \Cake\Routing\Router::reload();
        $routes = require ROOT . '/config/routes.php';
        $routes(\Cake\Routing\Router::createRouteBuilder('/'));
        $request = new \Cake\Http\ServerRequest(['url' => '/pcm/equipamento', 'environment' => ['REQUEST_METHOD' => 'GET']]);
        self::assertSame('equipment', \Cake\Routing\Router::parseRequest($request)['action']);
        $calls = [];
        $data = (new EquipmentHistoryService($this->repository($calls)))->load(['bem' => 'MEL 80 115', 'filial' => '01']);
        $data['summary'] += array_fill_keys(['total','open_count','closed_count','corrective','preventive','improvement',
            'canceled_count','pending_count','recurrence30','recurrence90','recurrence365','cost_center_count','area_count'], 0)
            + ['cost_center' => '', 'area' => 'ELETRI'];
        $data['orders'] = [['TJ_ORDEM' => '004368', 'TJ_FILIAL' => '01', 'reference_date' => '2026-08-18',
            'TJ_TIPO' => 'COR', 'TJ_SERVICO' => 'ELEPRE', 'service_name' => 'PREVENTIVA ELETRICA',
            'descricao' => '<script>unsafe</script>', 'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'S', 'TJ_CCUSTO' => '', 'TJ_CODAREA' => 'ELETRI']];
        $view = new \Cake\View\View($request);
        $view->setTemplatePath('Pcm');
        $view->set('equipment', $data);
        $html = $view->render('equipment', false);
        self::assertStringContainsString('18/08/2026', $html);
        self::assertStringContainsString('/pcm/protheus/os/004368?filial=01', $html);
        self::assertStringNotContainsString('<script>unsafe</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Corretiva', $html);
        self::assertStringContainsString('PREVENTIVA ELETRICA', $html);
        self::assertStringContainsString('pcm-sector-table-scroll', $html);
    }

    private function repository(array &$calls, bool $fail = false): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use (&$calls, $fail) {
            $calls[] = [$sql, $params, $types];
            self::assertTrue(ProtheusQueries::allows($sql));
            if ($fail) throw new \RuntimeException('SQLSTATE private host');
            $rows = match ($sql) {
                Q::HEADER => [['T9_CODBEM' => 'MEL 80 115', 'T9_NOME' => 'MOTOR']],
                Q::summary() => [['identity_count' => 1, 'all_count' => 2]],
                default => array_fill(0, 2, ['identity_count' => 1, 'equipment_matches' => 1, 'service_matches' => 1, 'TJ_ORDEM' => '004368']),
            };
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        return new ProtheusRepository($connection);
    }
}
