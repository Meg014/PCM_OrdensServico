<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\AuthenticatedUserTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class PcmControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AuthenticatedUserTrait;

    public function testRemovedSnapshotRoutesAreNotPubliclyRouted(): void
    {
        foreach (['/pcm/legado', '/pcm/ordens/legado', '/pcm/current-version',
            '/pcm/apresentacao/legado', '/pcm/apresentacao/legado/data', '/pcm/os/1',
            '/pcm/analises', '/pcm/analises/qualidade/missing_service', '/pcm/movimentacao/new'] as $url) {
            $this->get($url);
            $this->assertResponseCode(404, $url);
        }
    }

    public function testSectorCardsKeepTheirDrilldownThroughTheRealHttpFlow(): void
    {
        // This integration test intentionally exercises the configured read-only Protheus source.
        ConnectionManager::dropAlias('protheus');
        try {
        $this->get('/pcm/setor/ELETRI');
        $this->assertResponseOk();
        $sector = (string)$this->_response->getBody();

        $offseason = $this->hrefContaining($sector, 'card=offseason');
        self::assertStringStartsWith('/pcm/ordens?', $offseason);
        self::assertStringContainsString('area=ELETRI', $offseason);
        self::assertStringContainsString('analitico=1', $offseason);
        self::assertStringContainsString('card_status=EM+ABERTO', $offseason);
        $this->assertNoEmptyDrilldownState($offseason);
        $this->get($offseason);
        $this->assertResponseOk();
        $offseasonPage = (string)$this->_response->getBody();
        self::assertStringContainsString('57 O.S. encontradas', $offseasonPage);
        self::assertStringContainsString('2425EL', $offseasonPage);
        self::assertStringNotContainsString('CORELE', $offseasonPage);
        self::assertStringNotContainsString('005503', $offseasonPage);
        self::assertStringNotContainsString('>Fechada<', $offseasonPage);
        self::assertStringContainsString('name="card" value="offseason"', $offseasonPage);
        self::assertStringContainsString('name="card_status" value="EM ABERTO"', $offseasonPage);
        $offseasonPageTwo = $this->hrefContaining($offseasonPage, 'page=2');
        self::assertStringContainsString('card=offseason', $offseasonPageTwo);
        $this->get($offseasonPageTwo);
        $this->assertResponseOk();
        $offseasonSecondPage = (string)$this->_response->getBody();
        self::assertStringContainsString('Filtro ativo: Elétrica · Entressafra · Em aberto', $offseasonSecondPage);
        self::assertStringContainsString('2425EL', $offseasonSecondPage);
        self::assertStringNotContainsString('CORELE', $offseasonSecondPage);
        self::assertStringNotContainsString('005503', $offseasonSecondPage);
        self::assertStringNotContainsString('>Fechada<', $offseasonSecondPage);
        $offseasonExport = $this->hrefContaining($offseasonPage, '/pcm/ordens/excel');
        self::assertStringContainsString('card=offseason', $offseasonExport);
        self::assertStringContainsString('card_status=EM+ABERTO', $offseasonExport);

        $refinedOffseason = $offseason . '&bem=FAB%2080%20398';
        $this->get($refinedOffseason);
        $this->assertResponseOk();
        $refinedPage = (string)$this->_response->getBody();
        self::assertStringContainsString('name="card" value="offseason"', $refinedPage);
        self::assertStringContainsString('name="card_status" value="EM ABERTO"', $refinedPage);
        self::assertStringNotContainsString('CORELE', $refinedPage);
        self::assertStringNotContainsString('005503', $refinedPage);

        $backlog = $this->hrefContaining($sector, 'backlog_age=over_60');
        self::assertStringStartsWith('/pcm/ordens?', $backlog);
        self::assertStringContainsString('area=ELETRI', $backlog);
        self::assertStringContainsString('analitico=1', $backlog);
        self::assertStringContainsString('safra=1', $backlog);
        $this->assertNoEmptyDrilldownState($backlog);
        $this->get($backlog);
        $this->assertResponseOk();
        $backlogPage = (string)$this->_response->getBody();
        self::assertStringContainsString('17 O.S. encontradas', $backlogPage);
        self::assertStringNotContainsString('005503', $backlogPage);
        self::assertStringNotContainsString('2425EL', $backlogPage);
        self::assertStringNotContainsString('>Fechada<', $backlogPage);
        self::assertStringContainsString('name="backlog_age" value="over_60"', $backlogPage);
        self::assertStringContainsString('name="safra" value="1"', $backlogPage);
        self::assertStringContainsString('backlog_age=over_60', $this->hrefContaining($backlogPage, '/pcm/ordens/excel'));
        } finally {
            ConnectionManager::alias('test_protheus', 'protheus');
        }
    }

    private function hrefContaining(string $html, string $needle): string
    {
        preg_match_all('~href="([^"]+)"~', $html, $matches);
        foreach ($matches[1] as $href) {
            $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_contains($decoded, $needle)) return $decoded;
        }
        $visible = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        self::fail('Link não encontrado: ' . $needle . ' | HTML: ' . substr(preg_replace('~\s+~', ' ', strip_tags((string)$visible)), 0, 1500));
    }

    private function assertNoEmptyDrilldownState(string $url): void
    {
        foreach (['card', 'card_status', 'backlog_age', 'analitico', 'safra'] as $key) {
            self::assertStringNotContainsString($key . '=&', $url);
            self::assertFalse(str_ends_with($url, $key . '='));
        }
    }
}
