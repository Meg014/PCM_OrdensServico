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
    public function testScopedAggregatesPaginationAndTemplateWithoutDatabase(): void
    {
        $calls = [];
        $sector = (new ProtheusSectorService($this->repository($calls)))->load('ELETRI',
            ['q' => "004368%';--", 'filial' => '01', 'status' => 'FECHADA', 'page' => '2', 'limit' => '1', 'date_start' => '2026-01-01']);
        self::assertTrue($sector['available']);
        self::assertCount(2, $calls);
        self::assertSame('ELETRI', $calls[0][1]['area']);
        self::assertSame("%004368~%';--%", $calls[0][1]['q']);
        self::assertArrayNotHasKey('date_start', $calls[0][1]);
        self::assertSame('2026-01-01', $calls[1][1]['date_start']);
        self::assertSame(1, $calls[1][1]['offset']);
        self::assertSame('integer', $calls[1][2]['fetch']);
        self::assertTrue($sector['has_more']);
        self::assertSame(1, $sector['cards']['safra_completed']);
        self::assertSame(0, $sector['cards']['corrective']);
        self::assertFalse(ProtheusQueries::allows(ProtheusSectorQueries::page() . '; SELECT 2'));
        self::assertStringContainsString("TJ_SITUACA = 'L' AND TJ_TERMINO = 'N'", $calls[0][0]);
        self::assertStringContainsString('ORDER BY planned_date DESC, record_id DESC', $calls[1][0]);

        if (!defined('ROOT')) require dirname(__DIR__, 4) . '/config/paths.php';
        require_once CAKE . 'Core/functions_global.php';
        \Cake\Core\Configure::write('App.namespace', 'App');
        \Cake\Core\Configure::write('App.encoding', 'UTF-8');
        \Cake\Core\Configure::write('App.paths.templates', [ROOT . '/templates/']);
        \Cake\Cache\Cache::setConfig('_cake_translations_', ['className' => \Cake\Cache\Engine\NullEngine::class]);
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
        self::assertStringNotContainsString('<script>unsafe</script>', $html);
    }

    public function testFailureDoesNotPublishPartialAggregates(): void
    {
        $calls = [];
        $result = (new ProtheusSectorService($this->repository($calls, true)))->load('MECANI', []);
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
        self::assertSame('EM ABERTO', $calls[1][1]['card_status']);
        self::assertSame('MECOPO', $calls[1][1]['card_service1']);
        self::assertSame('ELECOP', $calls[1][1]['card_service2']);
        self::assertSame('BEM01', $calls[1][1]['equipment']);
        self::assertSame(1, $calls[1][1]['offset']);
        self::assertStringContainsString('filtered.status = card.status', $calls[1][0]);

        $calls = [];
        (new ProtheusSectorService($this->repository($calls)))->load('ELETRI', ['card' => 'corrective', 'card_status' => 'FECHADA']);
        self::assertSame('COR', $calls[1][1]['card_type']);
        self::assertSame('FECHADA', $calls[1][1]['card_status']);
        $calls = [];
        (new ProtheusSectorService($this->repository($calls)))->load('ELETRI', ['card' => 'safra']);
        self::assertSame([['code' => 'ELEPRE', 'name' => 'PREVENTIVA ELETRICA']], json_decode($calls[1][1]['season_services'], true));
    }

    private function repository(array &$calls, bool $fail = false, ?array $aggregate = null): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use (&$calls, $fail, $aggregate) {
            $calls[] = [$sql, $params, $types];
            self::assertTrue(ProtheusQueries::allows($sql));
            if ($fail && count($calls) === 2) throw new \RuntimeException('SQLSTATE private server');
            $base = ['identity_count' => 1, 'equipment_matches' => 1, 'service_matches' => 1, 'quantity' => 1, 'missing_start' => 0];
            $order = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '004368', 'TJ_CODBEM' => 'MEL 80 115', 'equipment_name' => '<script>unsafe</script>',
                'TJ_SERVICO' => 'ELEPRE', 'service_name' => 'PREVENTIVA ELETRICA', 'TJ_TIPO' => 'COR', 'TJ_CCUSTO' => '',
                'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'S', 'status' => 'FECHADA', 'planned_date' => '2026-01-01',
                'TJ_HOMPINI' => '', 'TJ_DTPRINI' => '', 'TJ_HOPRINI' => ''] + $base;
            $rows = count($calls) === 1 ? [['dimension' => 'total'] + $base, ['dimension' => 'cards'] + $order] : [$order, $order];
            if (count($calls) === 1 && $aggregate !== null) $rows = $aggregate;
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->expects(self::once())->method('closeCursor');
            return $statement;
        });
        return new ProtheusRepository($connection);
    }
}
