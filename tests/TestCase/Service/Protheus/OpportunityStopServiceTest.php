<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OpportunityStopService;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpportunityStopServiceTest extends TestCase
{
    public function testMaintenancePresentationUsesOnlyExactOpportunityServices(): void
    {
        foreach (['ELECOP', 'MECOPO '] as $code) {
            $row = ['TJ_ORDEM' => '006035', 'TJ_SERVICO' => $code, 'TJ_TIPO' => 'COR'];
            $original = $row;
            self::assertSame('Parada por Oportunidade', OpportunityStopService::maintenanceTypeLabel($row));
            self::assertSame($original, $row);
        }
        foreach (['COR' => 'Corretiva', 'PRE' => 'Preventiva', 'MEL' => 'Melhoria', 'OTHER' => 'OTHER', '' => ''] as $type => $label) {
            foreach (['CORPRO', 'ELECOPX', 'MEC OPO', ''] as $code) {
                self::assertSame($label, OpportunityStopService::maintenanceTypeLabel([
                    'TJ_SERVICO' => $code, 'TJ_TIPO' => $type,
                    'service_name' => 'MANUT.CORRET.PARADA POR OPORT.',
                ]));
            }
        }
    }

    public function testOpportunityTablePresentsOrder006035WithoutChangingOriginalType(): void
    {
        if (!defined('ROOT')) {
            require dirname(__DIR__, 4) . '/config/paths.php';
        }
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
        $orders = [];
        foreach (['ELECOP', 'MECOPO'] as $index => $code) {
            $orders[] = ['TJ_ORDEM' => $index === 0 ? '006035' : '006036', 'TJ_FILIAL' => '01',
                'TJ_SERVICO' => $code, 'TJ_TIPO' => 'COR', 'TJ_CODAREA' => 'ELETRI',
                'TJ_CODBEM' => 'EQ001', 'descricao' => 'MANUT.CORRET.PARADA POR OPORT.'];
        }
        $stops = ['available' => true, 'filters' => ['cost_center' => ''], 'area' => '', 'unit' => '',
            'total' => 2, 'page' => 2, 'limit' => 2, 'has_more' => true, 'orders' => $orders];
        $view = new \Cake\View\View(new \Cake\Http\ServerRequest(['url' => '/pcm/paradas-oportunidade']));
        $view->setTemplatePath('Pcm');
        $view->set(['stops' => $stops, 'workshops' => OpportunityStopService::WORKSHOPS,
            'units' => OpportunityStopService::UNITS, 'costCenters' => []]);
        $html = $view->render('opportunity_stops', false);
        self::assertStringContainsString('006035', $html);
        self::assertSame(2, substr_count($html, '<td>Parada por Oportunidade</td>'));
        self::assertStringNotContainsString('<td>Corretiva</td>', $html);
        self::assertStringContainsString('Total: 2 O.S.', $html);
        self::assertStringContainsString('page=1', $html);
        self::assertStringContainsString('page=3', $html);
        self::assertStringContainsString('Exportar para Excel', $html);
        self::assertSame($orders, $stops['orders']);
    }

    public function testUsesTheExactCardRuleAndItsOpenCount(): void
    {
        $received = [];
        $loader = static function (string $area, array $filters, bool $export, ?int $page) use (&$received): array {
            $received = compact('area', 'filters', 'export', 'page');

            return [
                'available' => true,
                'orders' => [['TJ_CODAREA' => 'MECANI']],
                'breakdown' => ['opportunity' => ['open' => 14, 'closed' => 3]],
            ];
        };
        $result = (new OpportunityStopService(loader: $loader))->load(
            ['area' => 'MECANI', 'unit' => 'factory', 'cost_center' => '3101005', 'card' => 'preventive', 'status' => 'FECHADA'],
            true,
            2,
        );

        self::assertSame('MECANI', $received['area']);
        self::assertSame('opportunity', $received['filters']['card']);
        self::assertSame('EM ABERTO', $received['filters']['card_status']);
        self::assertSame('EM ABERTO', $received['filters']['status']);
        self::assertSame('3101005', $received['filters']['cost_center']);
        self::assertSame('factory', $received['filters']['opportunity_unit']);
        self::assertTrue($received['export']);
        self::assertSame(2, $received['page']);
        self::assertSame(14, $result['total']);
        self::assertSame('MECANI', $result['orders'][0]['TJ_CODAREA']);
        self::assertSame('MECÂNICA', $result['area_name']);
        self::assertSame('FÁBRICA', $result['unit_name']);
        self::assertSame(
            ['MECANI' => 'MECÂNICA', 'ELETRI' => 'ELÉTRICA'],
            (new OpportunityStopService())->workshops(),
        );
    }

    public function testClassifiesAndFormatsCostCentersWithoutMixingWorkshop(): void
    {
        $service = new OpportunityStopService();
        $centers = ['3101005' => '3101005 — EXTRAÇÃO', '4101005' => '4101005 — CALDEIRAS', '9901' => '9901 — APOIO'];

        self::assertSame('factory', OpportunityStopService::unitForCostCenter('3101005'));
        self::assertSame('mill', OpportunityStopService::unitForCostCenter('4101005'));
        self::assertSame('other', OpportunityStopService::unitForCostCenter('9901'));
        self::assertSame(['factory' => 'FÁBRICA', 'mill' => 'USINA', 'other' => 'OUTROS'], $service->units($centers));
        self::assertSame(['3101005' => '3101005 — EXTRAÇÃO'], $service->costCentersForUnit($centers, 'factory'));
        self::assertSame('3101005 — EXTRAÇÃO', OpportunityStopService::costCenterLabel([
            'TJ_CCUSTO' => '3101005', 'cost_center_name' => 'EXTRAÇÃO',
        ]));
    }

    public function testRejectsUnsafeAreaBeforeLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OpportunityStopService(loader: static fn(): array => []))->load(['area' => "MECANI' OR 1=1"]);
    }

    public function testRejectsUnknownUnitBeforeLoading(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OpportunityStopService(loader: static fn(): array => []))->load(['unit' => 'workshop-name']);
    }

    public function testCostCenterOptionsSqlIsFixedBoundAndAllowlisted(): void
    {
        foreach ([false, true] as $withWorkshop) {
            $sql = ProtheusSectorQueries::opportunityCostCenters($withWorkshop);
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertStringContainsString('TJ_CCUSTO', $sql);
            self::assertStringContainsString('dbo.CTT010', $sql);
            self::assertStringContainsString('c.CTT_FILIAL = j.TJ_FILIAL', $sql);
            self::assertStringContainsString('c.CTT_CUSTO = j.TJ_CCUSTO', $sql);
            self::assertStringContainsString("c.D_E_L_E_T_ <> '*'", $sql);
            self::assertStringContainsString('ORDER BY name, code', $sql);
            self::assertStringContainsString(':service1', $sql);
            self::assertStringContainsString(':service2', $sql);
            self::assertSame($withWorkshop, str_contains($sql, ':area'));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        }
    }

    public function testOpportunityPageResolvesOneCostCenterNameWithCodeFallback(): void
    {
        $sql = ProtheusSectorQueries::page(false, true, true);

        self::assertTrue(ProtheusQueries::allows($sql));
        self::assertStringContainsString('OUTER APPLY', $sql);
        self::assertStringContainsString('MAX(NULLIF(LTRIM(RTRIM(c.CTT_DESC01))', $sql);
        self::assertStringContainsString('c.CTT_FILIAL = filtered.TJ_FILIAL', $sql);
        self::assertStringContainsString('c.CTT_CUSTO = filtered.TJ_CCUSTO', $sql);
        self::assertStringContainsString("c.D_E_L_E_T_ <> '*'", $sql);
        self::assertStringContainsString('AS cost_center_name', $sql);
        self::assertStringContainsString('f.cost_center = \'\' OR n.TJ_CCUSTO = f.cost_center', $sql);
        self::assertStringContainsString("f.unit = 'factory'", $sql);
        self::assertStringContainsString("LIKE '31%'", $sql);
        self::assertStringContainsString("f.unit = 'mill'", $sql);
        self::assertStringContainsString("LIKE '41%'", $sql);
        self::assertStringContainsString("f.unit = 'other'", $sql);
    }
}
