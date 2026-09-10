<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class PcmEmptyControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use PcmSnapshotFixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connection()->begin();
        self::clearPcmData();
    }

    public static function tearDownAfterClass(): void
    {
        self::connection()->rollback();
        parent::tearDownAfterClass();
    }

    public function testGeneralDashboardShowsNotUpdatedWithoutSuccessfulImport(): void
    {
        $this->get('/pcm');
        $this->assertResponseOk();
        $this->assertResponseContains('Dados ainda não atualizados');
    }

    public function testAnalysesShowsFriendlyHistoryMessageWithoutSnapshots(): void
    {
        $this->get('/pcm/analises');
        $this->assertResponseOk();
        $this->assertResponseContains('Histórico disponível após a importação de novos relatórios.');
    }
}
