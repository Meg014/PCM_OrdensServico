<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class PcmControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use PcmSnapshotFixture;

    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connection()->begin();
        self::$ids = self::seedValidatedSnapshot();
    }

    public static function tearDownAfterClass(): void
    {
        self::connection()->rollback();
        parent::tearDownAfterClass();
    }

    public function testDashboardReturnsHttp200(): void
    {
        $this->get('/pcm');
        $this->assertResponseOk();
        $this->assertResponseContains('Visão Geral');
        $this->assertResponseContains('OS Em Aberto');
        $this->assertResponseContains('OS Fechadas');
        $this->assertResponseContains('Preventivas');
        $this->assertResponseContains('Corretivas');
        $this->assertResponseContains('Melhorias');
        $this->assertResponseNotContains('OS Canceladas');
        $this->assertResponseNotContains('Eficiência');
        $this->assertResponseNotContains('Não iniciada');
        $this->assertResponseContains('Dados atualizados em:');
        $this->assertResponseContains('21/08/2026 às 06:37');
        $this->assertResponseContains('Atualização automática ativa');
        $this->assertResponseContains('pcm-auto-refresh.js');
        $this->assertResponseContains('data-theme="dark"');
        $this->assertResponseContains('data-pcm-theme-toggle');
        $this->assertResponseContains('pcm-theme.js');
        $this->assertResponseContains('Modo Apresentação');
        $this->assertResponseContains('Corretivas Emergenciais');
        $this->assertResponseContains('Corretivas Programadas');
        $this->assertResponseContains('Entressafra');
        $this->assertResponseContains('/pcm/ordens?');
    }

    public function testCompanyWideReportAndClassifiedEmptyResultsRender(): void
    {
        $this->get('/pcm/ordens?classification=ENTRESSAFRA&status=EM%20ABERTO');
        $this->assertResponseOk();
        $this->assertResponseContains('Todas as áreas');
        $this->assertResponseContains('Resumo por serviço');
        $this->assertResponseContains('Nome Serviço');
        $this->assertResponseContains('Centro de custo');
        $this->assertResponseContains('Nenhuma OS encontrada');
    }

    public function testSectorClassificationCardRetainsItsSectorRoute(): void
    {
        $this->get('/pcm/setor/MECANI');
        $this->assertResponseOk();
        $this->assertResponseContains('/pcm/setor/MECANI?');
        $this->assertResponseContains('classification=EMERGENCIAL');
        $this->assertResponseContains('Resumo por serviço');
    }

    public function testCurrentVersionReturnsCurrentImportMetadataOnly(): void
    {
        $this->get('/pcm/current-version');
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $payload = json_decode((string)$this->_response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'report_date' => '2026-08-21',
            'import_id' => self::$ids['currentImportId'],
            'updated_at' => '2026-08-21T09:37:00+00:00',
        ], $payload);
        $this->assertArrayNotHasKey('orders', $payload);
    }

    public function testCurrentVersionReturnsLaterImportWithSameReportDate(): void
    {
        $connection = self::connection();
        $connection->insert('report_imports', [
            'file_name' => 'same-date-endpoint.csv', 'file_path' => 'test/same-date-endpoint.csv',
            'report_date' => '2026-08-21', 'file_hash' => str_repeat('e', 64), 'file_size' => 1,
            'sheet_name' => 'sclxd280', 'status' => 'success',
            'started_at' => '2026-08-21 11:00:00', 'finished_at' => '2026-08-21 11:01:00',
            'created' => '2026-08-21 11:00:00', 'updated' => '2026-08-21 11:01:00',
        ]);
        $newImportId = (int)$connection->getDriver()->lastInsertId();

        try {
            $this->get('/pcm/current-version');
            $this->assertResponseOk();
            $payload = json_decode((string)$this->_response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertGreaterThan(self::$ids['currentImportId'], $newImportId);
            $this->assertSame($newImportId, $payload['import_id']);
            $this->assertSame('2026-08-21', $payload['report_date']);
            $this->assertSame('2026-08-21T11:01:00+00:00', $payload['updated_at']);
        } finally {
            $connection->delete('report_imports', ['id' => $newImportId]);
        }
    }

    public function testPresentationPageContainsOnlySummaryCardsAndControls(): void
    {
        $this->get('/pcm/apresentacao');
        $this->assertResponseOk();
        $this->assertResponseContains('PCM - VISÃO GERAL');
        $this->assertResponseContains('OS EM ABERTO');
        $this->assertResponseContains('OS FECHADAS');
        $this->assertResponseContains('PREVENTIVAS');
        $this->assertResponseContains('CORRETIVAS');
        $this->assertResponseContains('MELHORIAS');
        $this->assertResponseContains('Próxima tela em');
        $this->assertResponseContains('Sair da apresentação');
        $this->assertResponseNotContains('OS CANCELADAS');
        $this->assertResponseNotContains('Top 10 equipamentos');
    }

    public function testPresentationDataReturnsLightweightOrderedScreens(): void
    {
        $this->get('/pcm/apresentacao/data');
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $payload = json_decode((string)$this->_response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::$ids['currentImportId'], $payload['import_id']);
        $this->assertSame(['general', 'ELETRI', 'MECANI'], array_column($payload['screens'], 'key'));
        $this->assertSame(158, $payload['screens'][0]['open']);
        $this->assertSame(371, $payload['screens'][0]['completed']);
        $this->assertSame(40, $payload['screens'][0]['preventive']);
        $this->assertSame(40, $payload['screens'][0]['corrective']);
        $this->assertSame(39, $payload['screens'][0]['improvement']);
        $this->assertSame(39, $payload['screens'][0]['blank_maintenance_type']);
        $this->assertArrayNotHasKey('orders', $payload);
        $this->assertArrayNotHasKey('cancelled', $payload['screens'][0]);
    }

    public function testGenericSectorRouteReturnsHttp200(): void
    {
        $this->get('/pcm/setor/MECANI');
        $this->assertResponseOk();
        $this->assertResponseContains('Mecânica');
        $this->assertResponseContains('375');
        $this->assertResponseNotContains('OS Canceladas');
        $this->assertResponseContains('Canceladas — auditoria');
        $this->assertResponseContains('Preventivas');
        $this->assertResponseContains('Corretivas');
        $this->assertResponseContains('Melhorias');
        $this->assertResponseContains('Top 10 equipamentos');
        $this->assertResponseContains('Ordens de Serviço');
        $this->assertResponseContains('21/08/2026 às 06:37');
    }

    public function testElectricalDashboardAndPaginationReturnHttp200(): void
    {
        $this->get('/pcm/setor/ELETRI?page=2');
        $this->assertResponseOk();
        $this->assertResponseContains('Elétrica');
        $this->assertResponseContains('4396');
    }

    public function testFiltersLimitDashboardAndTable(): void
    {
        $this->get('/pcm/setor/MECANI?equipment=EQ-M1&status=FECHADA');
        $this->assertResponseOk();
        $this->assertResponseContains('250');
        $this->assertResponseContains('EQ-M1');
    }

    public function testOrderDetailsAndHistoryAreAvailable(): void
    {
        $row = self::connection()->execute("SELECT id FROM work_order_snapshots WHERE report_date = '2026-08-21' AND source_order_number = '4001'")->fetch('assoc');
        $this->get('/pcm/os/' . $row['id']);
        $this->assertResponseOk();
        $this->assertResponseContains('OS 4001');
        $this->assertResponseContains('Snapshots existentes');
        $this->assertResponseContains('PLANEJAMENTO / REGISTRO');
        $this->assertResponseContains('P. In. Man. — data/hora');
        $this->assertResponseContains('EXECUÇÃO REAL');
        $this->assertResponseContains('Real. Início — data/hora');
        $this->assertResponseContains('Real. Fim — data/hora');
        $this->assertResponseContains('R. In. Man. — data/hora');
        $this->assertResponseContains('20/08/2026');
        $this->assertResponseContains('21/08/2026');
        $this->assertResponseContains('STATUS: EM ABERTO → FECHADA');
    }

    public function testMissingAreaReturns404(): void
    {
        $this->get('/pcm/setor/INEXISTENTE');
        $this->assertResponseCode(404);
    }

    public function testAnalysesDisplaysDataQualitySection(): void
    {
        $this->get('/pcm/analises');
        $this->assertResponseOk();
        $this->assertResponseContains('QUALIDADE DOS DADOS');
        $this->assertResponseContains('Qualidade dos dados referente a: 21/08/2026');
    }

    public function testDataQualityDetailLinksToExistingOrderPage(): void
    {
        self::connection()->update('work_order_snapshots', ['cost_center_code' => null], [
            'report_date' => '2026-08-21', 'source_order_number' => '4001',
        ]);
        try {
            $this->get('/pcm/analises/qualidade/missing_cost_center');
            $this->assertResponseOk();
            $this->assertResponseContains('INCONSISTÊNCIAS ENCONTRADAS');
            $this->assertResponseContains('OS sem centro de custo');
            $this->assertResponseContains('Ver OS');
        } finally {
            self::connection()->update('work_order_snapshots', ['cost_center_code' => '4101002'], [
                'report_date' => '2026-08-21', 'source_order_number' => '4001',
            ]);
        }
    }
}
