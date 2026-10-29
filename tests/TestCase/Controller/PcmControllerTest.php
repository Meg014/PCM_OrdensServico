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

        self::assertStringContainsString('Elétrica e Instrumentação', $sector);
        foreach (['card=offseason', 'backlog_age=over_60'] as $selection) {
            $url = $this->hrefContaining($sector, $selection);
            self::assertStringStartsWith('/pcm/setor/ELETRI?', $url);
            $this->get($url);
            $this->assertResponseOk();
            $page = (string)$this->_response->getBody();
            self::assertStringContainsString('Limpar filtro do card', $page);
            self::assertStringContainsString($selection, $this->hrefContaining($page, '/pcm/setor/ELETRI/excel'));
            self::assertStringNotContainsString('&amp;amp;', $page);
            preg_match_all('~href="([^"]+)"~', $page, $links);
            foreach ($links[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!str_starts_with($href, '/pcm/setor/ELETRI?') || !str_contains($href, 'page=2')) continue;
                self::assertStringContainsString($selection, $href);
                $this->get(explode('#', $href)[0]);
                $this->assertResponseOk();
                self::assertStringContainsString('Limpar filtro do card', (string)$this->_response->getBody());
                break;
            }
        }
        $this->get('/pcm/setor/INSTRU?unit=factory&date_start=2026-10-01&date_end=2026-10-29');
        $this->assertRedirect('/pcm/setor/ELETRI?unit=factory&date_start=2026-10-01&date_end=2026-10-29');
        } finally {
            ConnectionManager::alias('test_protheus', 'protheus');
        }
    }

    public function testUnitButtonsApplyConfirmedFiltersAndKeepTheirState(): void
    {
        ConnectionManager::dropAlias('protheus');
        try {
            $this->get('/pcm/setor/ELETRI?unit=factory&equipment=RED%2080%20047&filial=01&date_start=2026-10-01&date_end=2026-10-29');
            $this->assertResponseOk();
            $html = (string)$this->_response->getBody();
            self::assertLessThan(strpos($html, 'data-sector-filter-form'), strpos($html, 'data-sector-unit-filter'));
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new \DOMXPath($dom);
            $links = $xpath->query('//a[@data-unit-choice]');
            self::assertSame(6, $links->length);
            self::assertSame(['', 'factory', 'mill', 'admin', 'external', 'sectors'], array_map(
                static fn ($link) => $link->getAttribute('data-unit-choice'), iterator_to_array($links),
            ));
            foreach ($links as $link) {
                $url = $link->getAttribute('href');
                parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
                $unit = $link->getAttribute('data-unit-choice');
                self::assertSame($unit, $query['unit']);
                self::assertSame('RED 80 047', $query['equipment']);
                self::assertSame('01', $query['filial']);
                self::assertSame('2026-10-01', $query['date_start']);
                self::assertSame('2026-10-29', $query['date_end']);
                self::assertSame('1', $query['page']);
                if ($unit === 'factory') self::assertSame('true', $link->getAttribute('aria-current'));
                if (!in_array($unit, ['admin', 'external', 'sectors'], true)) continue;
                $this->get($url);
                $this->assertResponseOk();
                $page = (string)$this->_response->getBody();
                self::assertStringContainsString('name="unit" value="' . $unit . '"', $page);
                self::assertStringContainsString('Remover filtro de equipamento', $page);
                self::assertStringContainsString('unit=' . $unit, $this->hrefContaining($page, '/pcm/setor/ELETRI/excel'));
                self::assertStringNotContainsString('&amp;amp;', $page);
            }
        } finally {
            ConnectionManager::alias('test_protheus', 'protheus');
        }
    }

    private function hrefContaining(string $html, string $needle): string
    {
        preg_match_all('~href="([^"]+)"~', $html, $matches);
        foreach ($matches[1] as $href) {
            $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_contains($decoded, $needle)) return explode('#', $decoded)[0];
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
