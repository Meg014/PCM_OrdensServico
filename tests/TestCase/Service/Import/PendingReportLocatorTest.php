<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\PendingReportLocator;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

final class PendingReportLocatorTest extends TestCase
{
    private string $directory;
    private mixed $originalIncoming;
    private mixed $originalMinimumAge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcm-pending-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
        $this->originalIncoming = Configure::read('Pcm.reports.incoming');
        $this->originalMinimumAge = Configure::read('Pcm.reports.minimumFileAgeSeconds');
        Configure::write('Pcm.reports.incoming', $this->directory);
        Configure::write('Pcm.reports.minimumFileAgeSeconds', 60);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        Configure::write('Pcm.reports.incoming', $this->originalIncoming);
        Configure::write('Pcm.reports.minimumFileAgeSeconds', $this->originalMinimumAge);
        parent::tearDown();
    }

    public function testFindsOnlyStableNonTemporaryXlsxFiles(): void
    {
        $ready = $this->directory . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-24.xlsx';
        file_put_contents($ready, 'xlsx');
        touch($ready, time() - 120);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . '~$Relatorio_OS_2026-08-25.xlsx', 'temp');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'notas.txt', 'texto');
        $recent = $this->directory . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-26.xlsx';
        file_put_contents($recent, 'xlsx');
        $csv = $this->directory . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-27.csv';
        file_put_contents($csv, 'csv');
        touch($csv, time() - 120);

        $files = (new PendingReportLocator())->scan();
        $filesByName = array_column($files, null, 'name');

        $this->assertCount(3, $files);
        $this->assertTrue($filesByName['Relatorio_OS_2026-08-24.xlsx']['ready']);
        $this->assertFalse($filesByName['Relatorio_OS_2026-08-26.xlsx']['ready']);
        $this->assertStringContainsString(
            'próxima execução',
            (string)$filesByName['Relatorio_OS_2026-08-26.xlsx']['reason'],
        );
        $this->assertTrue($filesByName['Relatorio_OS_2026-08-27.csv']['ready']);
    }
}
