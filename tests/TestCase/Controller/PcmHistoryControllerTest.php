<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class PcmHistoryControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use PcmSnapshotFixture;
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connection()->begin();
        self::$ids = self::seedHistoricalComparison();
    }

    public static function tearDownAfterClass(): void
    {
        self::connection()->rollback();
        parent::tearDownAfterClass();
    }

    public function testAnalysesPageShowsMovementAndSectorComparison(): void
    {
        $this->get('/pcm/analises');
        $this->assertResponseOk();
        $this->assertResponseContains('MOVIMENTAÇÃO DESDE O ÚLTIMO RELATÓRIO');
        $this->assertResponseContains('COMPARATIVO ENTRE SETORES');
        $this->assertResponseContains('21/08/2026 → 22/08/2026');
    }

    public function testGeneralDashboardRemainsExecutiveOnly(): void
    {
        $this->get('/pcm');
        $this->assertResponseOk();
        $this->assertResponseContains('Visão Geral');
        $this->assertResponseNotContains('Evolução entre relatórios');
        $this->assertResponseNotContains('COMPARATIVO ENTRE SETORES');
    }

    public function testGenericSectorShowsHistoricalSection(): void
    {
        $this->get('/pcm/setor/MECANI?history_period=7');
        $this->assertResponseOk();
        $this->assertResponseContains('Evolução entre relatórios');
        $this->assertResponseContains('Passaram para fechada');
    }

    public function testOrderTimelineShowsObservedStatusChange(): void
    {
        $row = self::connection()->execute(
            'SELECT id FROM work_order_snapshots WHERE report_import_id = :import AND source_order_number = :number',
            ['import' => self::$ids['nextImportId'], 'number' => '4001'],
        )->fetch('assoc');
        $this->get('/pcm/os/' . $row['id']);
        $this->assertResponseOk();
        $this->assertResponseContains('FECHADA → EM ABERTO');
        $this->assertResponseContains('Término: Sim → Não');
    }

    public function testMovementPageListsNewOrdersAndValidatesType(): void
    {
        $this->get('/pcm/movimentacao/new');
        $this->assertResponseOk();
        $this->assertResponseContains('Novas OS');
        $this->assertResponseContains('4999');
        $this->get('/pcm/movimentacao/invalida');
        $this->assertResponseCode(404);
    }
}
