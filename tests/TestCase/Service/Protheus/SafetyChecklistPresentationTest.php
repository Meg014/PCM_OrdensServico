<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OrderDescriptions;
use PHPUnit\Framework\TestCase;

final class SafetyChecklistPresentationTest extends TestCase
{
    private const QUESTIONS = [
        'ferramentas limpas antes do serviço? ( ) sim ( ) não ( ) n/a',
        'equipamento limpo após o serviço? ( ) sim ( ) não ( ) n/a',
        'sobras de materiais recolhidas? ( ) sim ( ) não ( ) n/a',
        'proteções, botões e sinalização conforme nr-12? ( ) sim ( ) não',
        'condições de segurança mantidas após o serviço? ( ) sim ( ) não',
    ];

    public function testShortQuestionsWithCaseSpacesAndLineBreaks(): void
    {
        foreach (self::QUESTIONS as $question) {
            foreach ([$question, mb_strtoupper($question), str_replace(' ', " \r\n\t", $question)] as $variant) {
                self::assertSame('Não informado', OrderDescriptions::display($variant));
                self::assertSame('Trocar  correia', OrderDescriptions::display("Trocar  correia\n" . $variant));
                $mixed = OrderDescriptions::display('Inspecionar redutor ' . $variant . ' Manter observação legítima.');
                self::assertStringContainsString('Inspecionar redutor', $mixed);
                self::assertStringContainsString('Manter observação legítima.', $mixed);
                self::assertStringNotContainsString('n/a', $mixed);
            }
        }
    }

    public function testOtherSafetyNotesAndTechnicalQuestionsStayIntact(): void
    {
        foreach (['Ferramentas limpas antes do serviço extra?', 'Verificar proteções conforme NR-12.', "Observação: não retirar proteção.\n\nRevisar  mancais."] as $text) {
            self::assertSame($text, OrderDescriptions::display($text));
        }
        self::assertSame('Não informado', OrderDescriptions::display(implode("\r\n", self::QUESTIONS)));
    }
}
