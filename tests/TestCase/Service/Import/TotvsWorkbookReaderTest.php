<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\TotvsWorkbookReader;
use Cake\TestSuite\TestCase;

final class TotvsWorkbookReaderTest extends TestCase
{
    public function testReadsRealTotvsReport(): void
    {
        $path = dirname(ROOT) . DIRECTORY_SEPARATOR . 'relatorios_teste' . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-21.xlsx';
        if (!is_file($path)) {
            $path = ROOT . DIRECTORY_SEPARATOR . 'relatorios' . DIRECTORY_SEPARATOR . 'processados'
                . DIRECTORY_SEPARATOR . 'c7d77770b4515c86' . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-21.xlsx';
        }
        $this->assertFileExists($path);
        $result = (new TotvsWorkbookReader())->read($path);
        $this->assertCount(57, $result['headers']);
        $this->assertCount(575, $result['rows']);
        $this->assertSame(2, $result['rows'][0]['source_row_number']);
        $this->assertSame('sclxd280', $result['sheet_name']);
    }

    public function testReadsNewRealReportWithSingleValidatedRenamedSheet(): void
    {
        $path = dirname(ROOT) . DIRECTORY_SEPARATOR . 'relatorios_teste' . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-24.xlsx';
        if (!is_file($path)) {
            $path = ROOT . DIRECTORY_SEPARATOR . 'relatorios' . DIRECTORY_SEPARATOR . 'processados'
                . DIRECTORY_SEPARATOR . '644429eb7ac9df04' . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-24.xlsx';
        }
        $this->assertFileExists($path);
        $result = (new TotvsWorkbookReader())->read($path);
        $this->assertSame('sclxdra0', $result['sheet_name']);
        $this->assertCount(57, $result['headers']);
        $this->assertNotEmpty($result['rows']);
        $this->assertStringContainsString('foi aceita', implode(' ', $result['warnings']));
    }
}
