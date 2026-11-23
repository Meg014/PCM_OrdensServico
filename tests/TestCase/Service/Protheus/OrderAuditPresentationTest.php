<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\ExecutedServicePresentation as Execution;
use App\Service\Protheus\OrderAuditSelection;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\Presentation\{OrderDetailPresenter,OrderSupplement,Availability};
use App\Service\ActionPlanExcelReport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class OrderAuditPresentationTest extends TestCase
{
    public function testStatusMatrixAndOriginals(): void
    {
        $cases = [
            ['L', 'N', false, 'Parcial', 'SERVIÇO EM ANDAMENTO', 'in_progress'],
            ['P', 'N', true, '', 'CHECKLIST EM ANDAMENTO', 'in_progress'],
            ['L', 'S', false, "Execução original.  ", "Execução original.  ", 'totvs_execution'],
            ['P', 'S', false, '', 'Descrição original', 'order_description'],
            ['L', 'S', true, 'Execução real', 'Execução real', 'totvs_execution'],
            ['L', 'S', true, '', 'CHECKLIST EXECUTADO', 'checklist_status'],
            ['C', 'S', true, 'Execução parcial', 'Execução parcial', 'totvs_execution'],
            ['C', 'N', false, '', 'Sem serviço executado registrado', 'missing'],
        ];
        foreach ($cases as [$situation,$ending,$checklist,$original,$expected,$source]) {
            $order = ['TJ_SITUACA' => $situation, 'TJ_TERMINO' => $ending, 'original_description' => 'Descrição original',
                'TJ_CODBEM' => $checklist ? 'PREVMEC FAB 01' : 'BOMBA', 'TJ_CODAREA' => 'MECANI'];
            $before = $order;
            $summary = Execution::resolve($original, $order);
            self::assertSame($expected, $summary['text']);
            self::assertSame($source, $summary['source']);
            self::assertSame($before, $order);
            if ($situation === 'C') self::assertSame('CANCELADA', $summary['status']);
        }
    }

    public function testStatusFilterIncludesPendingAndUsesInitialRatherThanUpdateUser(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE orders (TJ_SITUACA TEXT,TJ_TERMINO TEXT,TJ_USUAINI TEXT,TJ_USUARIO TEXT,D_E_L_E_T_ TEXT)');
        $db->exec("INSERT INTO orders VALUES ('L','N','Inicio','Alteracao',''),('P','N','Inicio','Outro',''),
            ('L','S','Outro','Inicio',''),('C','S','Inicio','Alteracao','')");
        foreach (['all' => 4, 'open' => 2, 'closed' => 1, 'cancelled' => 1] as $status => $count) {
            $base = "SELECT COUNT(*) FROM orders j WHERE j.D_E_L_E_T_ <> '*' AND " . \App\Service\Protheus\ProtheusOperationalEligibility::notCanceled('j');
            $sql = OrderAuditSelection::apply($base, $status, false);
            self::assertSame($count, (int)$db->query($sql)->fetchColumn());
            $sql = OrderAuditSelection::apply($base, $status, true);
            $statement = $db->prepare($sql); $statement->execute(['audit_initial_user' => 'Inicio']);
            self::assertSame($status === 'closed' ? 0 : ($status === 'all' ? 3 : $count), (int)$statement->fetchColumn());
            foreach ([ProtheusQueries::orders(false,false,false), ProtheusQueries::orders(true,true,true,true,false,false,true), ProtheusQueries::generalEntries(), \App\Service\Protheus\ActionPlanQuery::sql()] as $query) {
                $scoped = OrderAuditSelection::apply($query, $status, true);
                self::assertTrue(ProtheusQueries::allows($scoped));
                self::assertSame($query, OrderAuditSelection::remove($scoped));
                self::assertFalse(ProtheusQueries::allows($scoped . '; DELETE FROM STJ010'));
            }
        }
    }

    public function testCanceledDetailsKeepConfirmedReasonSeparateAndDoNotGuessMissingReason(): void
    {
        foreach ([[], ['reason' => 'Motivo original', 'source' => 'confirmed_test_source']] as $cancellation) {
            $supplement = new OrderSupplement(number: '000001', branch: '01', description: 'Descrição não é motivo',
                maintenance: ['situation' => 'C', 'ending' => 'S'], executedServiceAvailable: true, cancellation: $cancellation);
            $detail = (new OrderDetailPresenter())->present(['source_order_number' => '000001', 'branch_code' => '01'], $supplement, Availability::Available)['protheus'];
            self::assertSame('CANCELADA', $detail['order_status']);
            self::assertSame('Sem serviço executado registrado', $detail['executed_service']);
            self::assertSame($cancellation['reason'] ?? null, $detail['cancellation']['reason']);
            if ($cancellation === []) self::assertSame('Motivo do cancelamento não informado no TOTVS.', $detail['cancellation']['message']);
        }
    }

    public function testActionPlanKeepsColumnsAndAddsProvenanceInCellNotes(): void
    {
        $order = ['branch' => '01', 'number' => '000001', 'date' => '2026-11-23', 'sector' => 'Setor',
            'equipment' => 'Bem', 'mechanics' => '', 'service' => 'Serviço', 'description' => 'Descrição original',
            'original_description' => 'Descrição original', 'tasks' => '000001', 'status' => 'Finalizada',
            'TJ_TERMINO' => 'S', 'executed_service' => ''];
        $before = $order;
        $cancelled = array_replace($order, ['number' => '000002', 'TJ_SITUACA' => 'C', 'status' => 'Cancelada',
            'cancellation' => ['reason' => 'Motivo confirmado pela origem de teste', 'source' => 'confirmed_test_source']]);
        $stream = (new ActionPlanExcelReport())->write([$order, $cancelled]);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            self::assertSame(1, $book->getSheetCount());
            self::assertSame('000001', (string)$book->getSheet(0)->getCell('K9')->getValue());
            self::assertSame('000001', (string)$book->getSheet(0)->getCell('L9')->getValue());
            self::assertSame('Descrição original', (string)$book->getSheet(0)->getCell('M9')->getValue());
            self::assertStringContainsString('sem apontamento confirmado', $book->getSheet(0)->getComment('M9')->getText()->getPlainText());
            self::assertSame('Cancelada', (string)$book->getSheet(0)->getCell('N10')->getValue());
            self::assertSame('Sem serviço executado registrado', (string)$book->getSheet(0)->getCell('M10')->getValue());
            self::assertStringContainsString('Motivo confirmado pela origem de teste', $book->getSheet(0)->getComment('M10')->getText()->getPlainText());
            self::assertSame($before, $order);
        } finally { $book->disconnectWorksheets(); fclose($stream); }
    }
}
