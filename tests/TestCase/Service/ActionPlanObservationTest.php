<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service;

use App\Service\ActionPlanObservation as Observation;
use PHPUnit\Framework\TestCase;

final class ActionPlanObservationTest extends TestCase
{
    private const EXECUTED = 'foi feita a troca da correia do elevador 705.1 fizemos mltirao para fazer a substituiçao o mais rapio possivel';
    private const SHORT = "ferramentas limpas antes do serviço? ( ) sim ( ) não ( ) n/a\r\nequipamento limpo após o serviço? ( ) sim ( ) não ( ) n/a\r\nsobras de materiais recolhidas? ( ) sim ( ) não ( ) n/a\r\nproteções, botões e sinalização conforme nr-12? ( ) sim ( ) não\r\ncondições de segurança mantidas após o serviço? ( ) sim ( ) não";

    public function testExactEmployeeTextFrom007334IsNotRewritten(): void
    {
        $input = self::EXECUTED . "\r\n" . self::SHORT;
        $analysis = Observation::analyze($input);
        self::assertSame(self::EXECUTED . "\r\n", $analysis['text']);
        self::assertTrue($analysis['complete_checklist']); self::assertSame(5, $analysis['questions']);
        self::assertStringContainsString('mltirao', $analysis['text']); self::assertStringContainsString('rapio', $analysis['text']);
        self::assertSame(self::EXECUTED, Observation::withoutChecklist(self::EXECUTED));
        self::assertSame(self::EXECUTED, Observation::forExcel($input . "\r\n" . str_repeat(' ', 50) . "\0"));
    }
    public function testLegitimateNotesBeforeBetweenAndAfterQuestionsArePreservedVerbatim(): void
    {
        $before = "  Não mexer no ajuste!\r\n";
        $after = "Observação: aguardar a produção.\r\nMantido o texto  com  espaços.";
        self::assertSame($before . $after, Observation::withoutChecklist($before . self::SHORT . "\r\n" . $after));
        $input = "ferramentas limpas antes do serviço? (X) SIM\nA caixa não foi limpa porque está em operação.\nequipamento limpo após o serviço? [ ] NÃO\nPróxima visita: verificar NR-12.";
        self::assertSame("A caixa não foi limpa porque está em operação.\nPróxima visita: verificar NR-12.", Observation::withoutChecklist($input));
        self::assertSame(' Observação adicional.', Observation::withoutChecklist('condições de segurança mantidas após o serviço? ( ) sim ( ) não Observação adicional.'));
    }
    public function testLongChecklistAndCaseAccentCheckboxVariations(): void
    {
        $questions = ['AS FERRAMENTAS FORAM LIMPAS ANTES DO SERVICO?', 'O EQUIPAMENTO FOI LIMPO APOS O SERVICO?', 'TODA A SOBRA DE MATERIAL FOI RECOLHIDA?', 'AS CONDICOES DE SEGURANCA (PROTECAO/BOTOES/SINALIZACAO) ESTAO CONFORME (NR12)?', 'A CONCLUSAO DO SERVICO, E AS CONDICOES DE SEGURANCA FORAM MANTIDAS CONFORME NR.12?'];
        foreach ([' [X] SIM [ ] NAO ( ) N/A', ' ☑ sim ☐ não', ' SIM NÃO N/A'] as $answers) {
            $input = implode("\n", array_map(static fn ($q) => $q . $answers, $questions));
            self::assertSame('', Observation::withoutChecklist($input)); self::assertTrue(Observation::analyze($input)['complete_checklist']);
        }
    }
    public function testEmptyContentAndUnrelatedSafetyQuestionsAreNotInventedOrDeleted(): void
    {
        self::assertSame('', Observation::withoutChecklist(null)); self::assertSame('', Observation::withoutChecklist(''));
        self::assertSame('', Observation::withoutChecklist(self::SHORT));
        foreach (['  Peça trocada  ', 'Ferramentas limpas antes do serviço extra?', 'Verificar condições de segurança conforme NR-12.', 'O funcionário escreveu: ferramentas limpas antes do serviço?'] as $text) self::assertSame($text, Observation::withoutChecklist($text));
        self::assertFalse(Observation::analyze('ferramentas limpas antes do serviço?')['complete_checklist']);
        self::assertSame('', Observation::forExcel(self::SHORT . "\r\n  \0"));
    }

    public function testExcelCleansOnlyExplicitExecutionAndPreservesTheSeparatelyProvidedOpening(): void
    {
        $opening = 'Descrição de abertura de teste: substituir correia.';
        $original = self::EXECUTED . "\r\n" . self::SHORT . "\r\n" . str_repeat(' ', 50) . "\0";
        $order = ['number' => '007334', 'date' => '2026-11-06', 'sector' => 'FARELO', 'equipment' => 'ELV 80 001',
            'mechanics' => '', 'description' => $opening, 'service' => 'CORRETIVA MECANICA', 'status' => 'Aberta',
            'tasks' => '', 'executed_service' => $original];
        $stream = (new \App\Service\ActionPlanExcelReport())->write([$order]);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            $sheet = $book->getSheet(0);
            self::assertSame('007334', (string)$sheet->getCell('K9')->getValue());
            self::assertSame($opening, (string)$sheet->getCell('H9')->getValue());
            self::assertSame(self::EXECUTED, (string)$sheet->getCell('M9')->getValue());
            self::assertSame($original, $order['executed_service']);
        } finally { $book->disconnectWorksheets(); fclose($stream); }
    }

    public function testNeitherChecklistNorClosedStatusIsUsedToInferExecution(): void
    {
        foreach (['N', 'S'] as $status) {
            $orders = (new \App\Service\ActionPlanService())->group([[
                'TJ_FILIAL' => '01', 'TJ_ORDEM' => '007334', 'TJ_TERMINO' => $status,
                'reference_date' => '2026-11-06', 'cost_center_name' => 'FARELO', 'equipment_name' => 'ELV 80 001',
                'description' => self::EXECUTED . "\r\n" . self::SHORT, 'service_name' => 'CORRETIVA MECANICA',
            ]]);
            self::assertArrayNotHasKey('executed_service', $orders[0]);
            self::assertSame($status === 'S' ? 'Finalizada' : 'Aberta', $orders[0]['status']);
            $stream = (new \App\Service\ActionPlanExcelReport())->write($orders);
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(stream_get_meta_data($stream)['uri']);
            try { self::assertSame('', (string)$book->getSheet(0)->getCell('M9')->getValue()); }
            finally { $book->disconnectWorksheets(); fclose($stream); }
        }
    }

    public function testDiagnosticQueriesAndTheManualScriptAreReadOnly(): void
    {
        $queries = \App\Service\Protheus\ActionPlanObservationQueries::class;
        foreach ([$queries::SOURCES, $queries::HISTORY_METADATA, $queries::HEADER] as $sql) {
            self::assertTrue(\App\Service\Protheus\ProtheusQueries::allows($sql));
            self::assertFalse(\App\Service\Protheus\ProtheusQueries::allows($sql . '; DELETE FROM dbo.STJ010'));
        }
        self::assertStringContainsString('j.TJ_FILIAL = CAST(:branch AS varchar(100))', $queries::HEADER);
        self::assertStringContainsString('j.TJ_ORDEM = CAST(:number AS varchar(100))', $queries::HEADER);
        $script = file_get_contents(ROOT . '/docs/sql/plano-de-acao-investigar-observacoes.sql');
        $withoutComments = preg_replace('~/\*.*?\*/|--[^\r\n]*~s', '', $script);
        self::assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|MERGE|EXEC|GRANT|REVOKE)\b/i', $withoutComments);
    }
}
