<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\ActionPlanExcelReport;
use App\Service\ActionPlanExecution;
use App\Service\ActionPlanService;
use App\Service\Protheus\ExecutedServicePresentation;
use App\Service\Protheus\OrderDescriptions;
use App\Service\Protheus\Presentation\Availability;
use App\Service\Protheus\Presentation\OrderDetailPresenter;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use App\Service\Protheus\RouteChecklist;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class ExecutedServicePresentationTest extends TestCase
{
    private function scenarios(): array
    {
        return [
            ['PREVMEC FAB 01', 'MECANI', '', ExecutedServicePresentation::CHECKLIST],
            ['PREVLUB FAB 02', 'MECANI', 'ROTA DE LUBRIFICAÇÃO', 'ROTA DE LUBRIFICAÇÃO'],
            ['PREVELE FAB 01', 'INSTRU', "Conexões reapertadas.\nTexto original  ", "Conexões reapertadas.\nTexto original  "],
            ['BOMBA 01', 'MECANI', 'FOI FEITA A LIMPEZA NA UTE NOVA', 'FOI FEITA A LIMPEZA NA UTE NOVA'],
            ['BOMBA 02', 'MECANI', 'FOI FEITA A ATUALIZAÇÃO DO PATRIMÔNIO', 'FOI FEITA A ATUALIZAÇÃO DO PATRIMÔNIO'],
            ['BOMBA 03', 'MECANI', '', 'Descrição original'],
        ];
    }

    public function testSummaryAcrossDetailAndActionPlanExportPreservesHistoricalTextAndWorkbook(): void
    {
        $rows = []; $stages = [];
        foreach ($this->scenarios() as $i => [$equipment, $area, $text, $expected]) {
            $number = '00000' . $i;
            $main = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => $number, 'TJ_CODBEM' => $equipment,
                'TJ_CODAREA' => $area, 'TJ_SERVICO' => 'PREELE', 'TJ_CCUSTO' => '5101006', 'TJ_TERMINO' => 'S'];
            $source = ['numero' => $number, 'dados_principais' => $main, 'executed_service' => $text, 'executed_service_available' => true, 'original_description' => 'Descrição original',
                'mao_de_obra' => [['apontamento' => ['TL_TIPOREG' => 'M', 'pcm_entry_description' => $text]]]];
            $before = $source;
            $supplement = (new OrderSupplementMapper())->map($source);
            $detail = (new OrderDetailPresenter())->present(['source_order_number' => $number, 'branch_code' => '01'],
                $supplement, Availability::Available)['protheus'];
            self::assertSame($expected, $detail['executed_service']);
            self::assertTrue($detail['executed_service_available']);
            self::assertSame($text, $supplement->executedService);
            self::assertSame($before, $source);
            if ($text !== '') self::assertSame(trim($text), trim($detail['labor'][0]['description']));
            $rows[] = $main + ['reference_date' => '2026-11-23', 'cost_center_name' => 'Setor',
                'equipment_name' => 'Nome do equipamento', 'service_name' => 'Preventiva', 'description' => 'Descrição original'];
            if ($text !== '') $stages[] = ['TQ_FILIAL' => '01', 'TQ_ORDEM' => $number,
                'stage_record' => $i, 'executed_observation' => $text];
        }
        $beforeRows = $rows; $beforeStages = $stages;
        $orders = ActionPlanExecution::attach((new ActionPlanService())->group($rows), $stages);
        $stream = (new ActionPlanExcelReport())->write($orders);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            $sheet = $book->getSheet(0);
            foreach ($this->scenarios() as $i => [$equipment, $area, $text, $expected]) {
                self::assertSame($expected, (string)$sheet->getCell('M' . ($i + 9))->getValue());
                self::assertSame('00000' . $i, (string)$sheet->getCell('K' . ($i + 9))->getValue());
                self::assertSame($text, $orders[$i]['executed_service']);
                self::assertSame('Descrição original', $orders[$i]['description']);
            }
            self::assertSame($beforeRows, $rows);
            self::assertSame($beforeStages, $stages);
        } finally {
            $book->disconnectWorksheets();
            fclose($stream);
        }
    }

    public function testServiceCodeAndRouteWordingAloneCannotReplaceOrdinaryExecution(): void
    {
        foreach (['PREELE', 'PRELUB', 'PREMEC'] as $service) {
            $ordinary = ['TJ_FILIAL' => '01', 'TJ_CODBEM' => 'BOMBA', 'TJ_CODAREA' => 'MECANI',
                'TJ_SERVICO' => $service, 'equipment_name' => 'ROTA', 'service_name' => 'ROTA CHECKLIST'];
            self::assertFalse(RouteChecklist::isOrder($ordinary));
            self::assertSame('Execução original', ExecutedServicePresentation::display('Execução original', $ordinary));
            self::assertSame(OrderDescriptions::MISSING, ExecutedServicePresentation::display(null, $ordinary));
        }
        self::assertFalse(RouteChecklist::isOrder(['TJ_CODBEM' => 'PREVMEC FABRICA', 'TJ_CODAREA' => 'MECANI']));
    }

    public function testAuditedServiceIdentitiesAreRecognizedWithoutAreaAndWithoutNotes(): void
    {
        foreach (require ROOT . '/resources/data/action-plan-factory-routes.php' as $route) {
            $order = ['TJ_TERMINO' => 'S', 'branch' => $route['branch'], 'equipment_code' => $route['equipment'],
                'cost_center' => $route['cost_center'], 'service_code' => $route['service']];
            self::assertSame(ExecutedServicePresentation::CHECKLIST, ExecutedServicePresentation::display(null, $order));
            $order['service_code'] = 'OUTRO';
            self::assertSame(OrderDescriptions::MISSING, ExecutedServicePresentation::display(null, $order));
        }
    }

    public function testChecklistSummaryDoesNotDependOnExecutionSourceAvailability(): void
    {
        foreach (['PREVMEC FAB 01' => true, 'BOMBA 01' => false] as $equipment => $checklist) {
            $source = ['numero' => '000001', 'dados_principais' => ['TJ_FILIAL' => '01',
                'TJ_CODBEM' => $equipment, 'TJ_CODAREA' => 'MECANI', 'TJ_TERMINO' => 'N'], 'executed_service_available' => false];
            $supplement = (new OrderSupplementMapper())->map($source);
            $presenter = new OrderDetailPresenter();
            $snapshot = ['source_order_number' => '000001', 'branch_code' => '01'];
            $detail = $presenter->present($snapshot, $supplement, Availability::Available)['protheus'];
            self::assertTrue($detail['executed_service_available']);
            self::assertSame($checklist ? 'CHECKLIST EM ANDAMENTO' : 'SERVIÇO EM ANDAMENTO', $detail['executed_service']);
            $unavailable = $presenter->present($snapshot, $supplement, Availability::Unavailable)['protheus'];
            self::assertNull($unavailable['executed_service']);
            self::assertFalse($unavailable['executed_service_available']);
        }
    }

    public function testPhpRecognitionMatchesExistingSqlRouteFamilies(): void
    {
        $db = new \PDO('sqlite::memory:');
        $statement = $db->prepare('SELECT CASE WHEN ' . RouteChecklist::predicate() . ' THEN 1 ELSE 0 END'
            . ' FROM (SELECT :equipment TJ_CODBEM, :area TJ_CODAREA)');
        foreach (['PREVELE FAB', 'PREVLUB FAB 01', 'PREVMEC FAB 5B', 'prevlub fab 02  ',
            'PREVMEC FABRICA', 'ROTA 01', 'BOMBA 01', ''] as $equipment) {
            foreach (['MECANI', 'ELETRI', 'INSTRU', 'FAB', ''] as $area) {
                $statement->execute(['equipment' => $equipment, 'area' => $area]);
                self::assertSame((bool)$statement->fetchColumn(), RouteChecklist::isOrder([
                    'TJ_CODBEM' => $equipment, 'TJ_CODAREA' => $area,
                ]), $equipment . '/' . $area);
            }
        }
    }
}
