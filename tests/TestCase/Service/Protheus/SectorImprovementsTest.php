<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OrderDescriptions;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusSectorQueries;
use App\Service\Protheus\SectorPresentationScope;
use PHPUnit\Framework\TestCase;

final class SectorImprovementsTest extends TestCase
{
    private const CHECKLIST = "as ferramentas foram limpas antes do serviço?\t(  ) sim (  ) não (  ) não aplicável\r\n"
        . "o equipamento foi limpo após o serviço? ( ) sim ( ) não ( ) não aplicável\r\n"
        . "toda a sobra de material foi recolhida? ( ) sim ( ) não ( ) não aplicável\r\n"
        . "as condições de segurança (proteção/botões/sinalização) estão conforme (nr12)? ( ) sim ( ) não\r\n"
        . 'a conclusão do serviço, e as condições de segurança foram mantidas conforme (nr12)? ( ) sim ( ) não';

    public function testChecklistRequiresTheCompleteRecognizedSuffix(): void
    {
        $activities = "INSPECIONAR REDUTOR E ACOPLAMENTO\r\nELIMINAR VAZAMENTO DO REDUTOR\r\n"
            . "REVISAR MANCAIS E ROLETES\r\nREVISÃO DOS RASPADORES\r\nLIMPAR E VERIFICAR SEGURANÇA";
        $original = $activities . "\r\n\r\n" . self::CHECKLIST;
        self::assertSame($activities, OrderDescriptions::maintenance($original));
        self::assertSame($activities, OrderDescriptions::maintenance($activities));
        self::assertSame($activities, OrderDescriptions::maintenance($activities . "\n" . mb_strtoupper(self::CHECKLIST)));
        $unaccented = \Cake\Utility\Text::transliterate(self::CHECKLIST);
        self::assertSame($activities, OrderDescriptions::maintenance($activities . "\n" . $unaccented));
        foreach ([str_replace('toda a sobra de material foi recolhida?', 'outra atividade', $original),
            $original . "\nREVISAR PROTEÇÃO", $activities . "\nAs ferramentas foram limpas antes do serviço?"] as $ambiguous) {
            self::assertSame($ambiguous, OrderDescriptions::maintenance($ambiguous));
        }
        self::assertNull(OrderDescriptions::maintenance(null));
    }

    public function testUnifiedSectorIncludesOnlyItsTwoOriginalCodesWithoutDuplicatingOrders(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE orders (TJ_CODAREA TEXT, number TEXT)');
        $db->exec("INSERT INTO orders VALUES ('ELETRI','1'), ('INSTRU','2'), ('MECANI','3'), ('INSTRU2','4')");
        $sql = SectorPresentationScope::areas('SELECT number, TJ_CODAREA FROM orders j WHERE j.TJ_CODAREA = CAST(:area AS VARCHAR(100)) ORDER BY number');
        // SQL Server concatenates using +; SQLite uses ||.
        $sql = str_replace(' + ', ' || ', $sql);
        foreach (['ELETRI', 'INSTRU'] as $area) {
            $statement = $db->prepare($sql);
            $statement->execute(['area' => $area]);
            self::assertSame([['number' => '1', 'TJ_CODAREA' => 'ELETRI'], ['number' => '2', 'TJ_CODAREA' => 'INSTRU']], $statement->fetchAll(\PDO::FETCH_ASSOC));
        }
    }

    public function testReadOnlyAllowlistAcceptsOnlyFixedComposedScopes(): void
    {
        foreach ([ProtheusSectorQueries::aggregates(), ProtheusSectorQueries::backlog(),
            ProtheusSectorQueries::equipmentRanking(), ProtheusSectorQueries::historicalRankings(),
            ProtheusSectorQueries::page(), ProtheusSectorQueries::entriesPage()] as $base) {
            $sql = \App\Service\Protheus\EquipmentLookupQueries::withSubstring(SectorPresentationScope::period(SectorPresentationScope::areas($base)));
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM dbo.STJ010'));
            self::assertStringContainsString('n.planned_date >= TRY_CONVERT', $sql);
            self::assertStringContainsString('n.planned_date <= TRY_CONVERT', $sql);
        }
    }

    public function testInvalidBrazilianDatesAndReversedPeriodsAreRejectedBeforeConnecting(): void
    {
        foreach ([['date_start' => '31/02/2026'], ['date_end' => '29/02/2025'],
            ['date_start' => '20/10/2026', 'date_end' => '01/10/2026']] as $query) {
            try {
                (new \App\Service\Protheus\ProtheusSectorService())->load('ELETRI', $query);
                self::fail('Invalid dates accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertContains($e->getMessage(), ['Data inválida.', 'Período inválido.']);
            }
        }
    }
}
