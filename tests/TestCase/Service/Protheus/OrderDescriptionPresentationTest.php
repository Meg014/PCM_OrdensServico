<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OrderDescriptions as D;
use App\Service\Protheus\Presentation\OrderDetailPresenter;
use App\Service\Protheus\Presentation\OrderSupplement;
use App\Service\Protheus\Presentation\Availability;
use App\Service\OrderStreamingExport;
use PHPUnit\Framework\TestCase;

final class OrderDescriptionPresentationTest extends TestCase
{
    private const QUESTIONS = [
        'AS FERRAMENTAS FORAM LIMPAS ANTES DO SERVIÇO?',
        'O EQUIPAMENTO FOI LIMPO APÓS O SERVIÇO?',
        'TODA A SOBRA DE MATERIAL FOI RECOLHIDA?',
        'AS CONDIÇÕES DE SEGURANÇA (PROTEÇÃO/BOTÕES/SINALIZAÇÃO) ESTÃO CONFORME (NR12)?',
        'A CONCLUSÃO DO SERVIÇO, E AS CONDIÇÕES DE SEGURANÇA FORAM MANTIDAS CONFORME (NR12)?',
    ];
    private function checklist(): string { return implode("\r\n", array_map(static fn($q) => $q . ' (X) SIM [ ] NÃO ( ) NÃO APLICÁVEL', self::QUESTIONS)); }
    public function testChecklistOnlyAndNoFallbackToChecklistOrService(): void
    {
        self::assertSame(D::MISSING, D::display($this->checklist()));
        self::assertSame(D::MISSING, D::best($this->checklist(), ['Apontamento independente'], 'Serviço'));
        self::assertSame(D::MISSING, D::display(''));
    }
    public function testMixedChecklistAtBeginningMiddleEndAndTechnicalQuestions(): void
    {
        $activities = "INSPECIONAR REDUTOR E ACOPLAMENTO\r\nELIMINAR VAZAMENTO DO REDUTOR\r\nREVISAR MANCAIS E ROLETES";
        self::assertSame($activities, D::display($activities . "\r\n" . $this->checklist()));
        self::assertSame($activities, D::display($this->checklist() . "\r\n" . $activities));
        $middle = 'INSPECIONAR REDUTOR ' . self::QUESTIONS[0] . ' (x) SIM ( ) NÃO REVISAR MANCAIS';
        $clean = D::display($middle); self::assertStringContainsString('INSPECIONAR REDUTOR', $clean); self::assertStringContainsString('REVISAR MANCAIS', $clean);
        foreach (['O redutor está vazando?', 'As ferramentas foram limpas antes do serviço especial?', 'A proteção precisa ser substituída?', 'Verificar se as condições de segurança estão corretas.'] as $technical) self::assertSame($technical, D::display($technical));
    }
    public function testCaseAccentsWhitespaceLineBreaksPunctuationAndMarkedAnswers(): void
    {
        foreach (self::QUESTIONS as $question) {
            foreach ([$question, mb_strtolower($question), \Cake\Utility\Text::transliterate($question), str_replace(' ', " \n  ", $question), str_replace('?', ':', $question)] as $variant) {
                foreach ([' (x) SIM ( ) NÃO ( ) NÃO APLICÁVEL', ' [ ] SIM [X] NAO [ ] NAO APLICAVEL', ' ☑ SIM ☐ NÃO', "\nSIM NÃO NÃO APLICÁVEL", ''] as $answer) {
                    self::assertSame(D::MISSING, D::display($variant . $answer), $variant . $answer);
                }
            }
        }
        self::assertSame(D::MISSING, D::display('1. ' . self::QUESTIONS[0]));
    }
    public function testUncertainQuestionsAndActivityWhitespaceArePreserved(): void
    {
        $text = "INSPECIONAR  REDUTOR\n\nELIMINAR VAZAMENTO\nAs ferramentas foram limpas antes de desmontar o equipamento?";
        self::assertSame($text, D::display($text));
        self::assertSame($text, D::display($text . "\n" . $this->checklist()));
        self::assertSame(D::display($text), D::display(D::display($text)));
    }
    public function testDetailsAndAllStreamingSchemasUseSamePresentationAndKeepAudit(): void
    {
        $original = "REVISAR MANCAIS\n" . $this->checklist();
        $supplement = new OrderSupplement(number: '007042', branch: '01', description: $original,
            labor: [['description' => self::QUESTIONS[0]]], descriptions: [['source' => 'Descrição original da O.S.', 'text' => $original], ['source' => 'Apontamento', 'text' => self::QUESTIONS[1]]]);
        $detail = (new OrderDetailPresenter())->present(['source_order_number' => '007042', 'branch_code' => '01'], $supplement, Availability::Available)['protheus'];
        self::assertSame('REVISAR MANCAIS', $detail['description']); self::assertSame($original, $detail['original_description']);
        self::assertSame(self::QUESTIONS[0], $detail['labor'][0]['description']); self::assertSame(self::QUESTIONS[1], $detail['descriptions'][0]['text']);
        $export = new OrderStreamingExport(); $method = new \ReflectionMethod($export, 'orderColumns');
        foreach (['orders', 'sector', 'equipment'] as $context) {
            $columns = $method->invoke($export, $context);
            self::assertSame($detail['description'], $columns['descricao'][3](['descricao' => $original]));
            self::assertSame(D::MISSING, $columns['descricao'][3](['descricao' => $this->checklist()]));
        }
        $columns = (new \ReflectionMethod($export, 'entryColumns'))->invoke($export);
        self::assertSame($detail['description'], $columns['descricao'][3](['descricao' => $original]));
        self::assertSame($original, $supplement->description);
    }
}
