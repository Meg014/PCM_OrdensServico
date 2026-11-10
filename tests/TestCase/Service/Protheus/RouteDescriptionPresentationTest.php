<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\ActionPlanService;
use App\Service\ActionPlanExcelReport;
use App\Service\OrderStreamingExport;
use App\Service\Protheus\OrderDescriptions;
use App\Service\Protheus\Presentation\Availability;
use App\Service\Protheus\Presentation\OrderDetailPresenter;
use App\Service\Protheus\Presentation\OrderSupplement;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class RouteDescriptionPresentationTest extends TestCase
{
    public function testRoutesAndOrdinaryOrdersAcrossDetailsAndStreamingSchemas(): void
    {
        foreach (['PREVELE FAB 01', 'PREVLUB FAB 02', 'PREVMEC FAB 07', 'ELV 80 016'] as $code) {
            $expected = $code === 'ELV 80 016' ? 'Trocar correia' : 'ROTA 07 - RECEPÇÃO';
            $original = "Trocar correia\nferramentas limpas antes do serviço? ( ) sim ( ) não ( ) n/a";
            $row = ['descricao' => $original, 'TJ_CODBEM' => $code, 'equipment_name' => 'ROTA 07 - RECEPÇÃO'];
            $supplement = new OrderSupplement(number: '007396', branch: '01', description: $original,
                maintenance: ['equipment_code' => $code, 'equipment_name' => $row['equipment_name']],
                executedService: 'Serviço executado independente', taskActivities: ['tasks' => [['description' => 'Tarefa independente']]]);
            $detail = (new OrderDetailPresenter())->present(['source_order_number' => '007396', 'branch_code' => '01'], $supplement, Availability::Available)['protheus'];
            self::assertSame($expected, $detail['description']);
            self::assertSame('Serviço executado independente', $detail['executed_service']);
            self::assertSame($supplement->taskActivities, $detail['task_activities']);
            self::assertSame($original, $supplement->description);
            self::assertSame($expected, OrderDescriptions::listing($row));
            $export = new OrderStreamingExport();
            foreach (['orders', 'sector', 'equipment'] as $context) {
                $columns = (new \ReflectionMethod($export, 'orderColumns'))->invoke($export, $context);
                self::assertSame($expected, $columns['descricao'][3]($row));
            }
            $columns = (new \ReflectionMethod($export, 'entryColumns'))->invoke($export);
            self::assertSame($expected, $columns['descricao'][3]($row));
        }
    }

    public function testActionPlanGroupingCarriesEquipmentIdentityToDescriptionColumnOnly(): void
    {
        $rows = [];
        foreach (['PREVELE FAB 01', 'PREVLUB FAB 02', 'PREVMEC FAB 07', 'ELV 80 016'] as $i => $code) {
            $rows[] = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '00739' . $i, 'TJ_CODBEM' => $code,
                'reference_date' => '2026-11-10', 'cost_center_name' => 'Setor', 'equipment_name' => 'ROTA 07 - RECEPÇÃO',
                'description' => 'Trocar correia', 'service_name' => 'Preventiva', 'TJ_TERMINO' => 'N',
                'TL_TAREFA' => '001', 'task_description' => 'Tarefa independente'];
        }
        $orders = (new ActionPlanService())->group($rows);
        foreach ($orders as &$order) $order['executed_service'] = 'Execução independente';
        unset($order);
        $stream = (new ActionPlanExcelReport())->write($orders);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            $sheet = $book->getSheet(0);
            foreach ($orders as $i => $order) {
                $row = $i + 9;
                self::assertSame($i === 3 ? 'Trocar correia' : 'ROTA 07 - RECEPÇÃO', (string)$sheet->getCell('H' . $row)->getValue());
                self::assertSame($order['tasks'], (string)$sheet->getCell('L' . $row)->getValue());
                self::assertSame('Execução independente', (string)$sheet->getCell('M' . $row)->getValue());
                self::assertSame('Aberta', (string)$sheet->getCell('N' . $row)->getValue());
                self::assertSame('Trocar correia', $order['description']);
            }
        } finally { $book->disconnectWorksheets(); fclose($stream); }
    }
}
