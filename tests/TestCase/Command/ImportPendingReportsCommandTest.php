<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Service\Import\ReportFileProcessor;
use App\Service\Import\TotvsHeaderValidator;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ImportPendingReportsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use PcmSnapshotFixture;

    private string $temporaryRoot;
    private array $originalConfiguration = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::connection()->begin();
        static::clearPcmData();
    }

    public static function tearDownAfterClass(): void
    {
        static::connection()->rollback();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::clearPcmData();
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcm-command-' . bin2hex(random_bytes(6));
        foreach (['incoming', 'processed', 'error', 'staging'] as $key) {
            $this->originalConfiguration[$key] = Configure::read("Pcm.reports.{$key}");
            Configure::write("Pcm.reports.{$key}", $this->temporaryRoot . DIRECTORY_SEPARATOR . $key);
        }
        $this->originalConfiguration['minimumFileAgeSeconds'] = Configure::read('Pcm.reports.minimumFileAgeSeconds');
        Configure::write('Pcm.reports.minimumFileAgeSeconds', 0);
        $this->originalConfiguration['operationalFile'] = Configure::read('Pcm.reports.operationalFile');
        Configure::write('Pcm.reports.operationalFile', 'Relatorio_OS_ATUAL.xlsx');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalConfiguration as $key => $value) {
            Configure::write("Pcm.reports.{$key}", $value);
        }
        $this->removeTemporaryTree();
        parent::tearDown();
    }

    public function testProcessesRealReportContinuesAfterFailureAndIgnoresDuplicate(): void
    {
        $incoming = $this->temporaryRoot . DIRECTORY_SEPARATOR . 'incoming';
        mkdir($incoming, 0775, true);
        file_put_contents($incoming . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-20.xlsx', 'arquivo invalido');

        $realReport = $this->realReportPath();
        $this->assertFileExists($realReport);
        copy($realReport, $incoming . DIRECTORY_SEPARATOR . basename($realReport));

        $this->exec('import_pending_reports');

        $this->assertExitError();
        $this->assertOutputContains('Arquivo encontrado: Relatorio_OS_2026-08-20.xlsx');
        $this->assertOutputContains('Arquivo encontrado: Relatorio_OS_2026-08-21.xlsx');
        $this->assertOutputContains('Importado: Relatorio_OS_2026-08-21.xlsx');
        $this->assertOutputContains('575 registros');
        $this->assertErrorContains('Erro em Relatorio_OS_2026-08-20.xlsx');
        $this->assertCount(1, $this->xlsxFiles('processed'));
        $this->assertCount(1, $this->xlsxFiles('error'));
        $this->assertFileExists($incoming . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-20.xlsx');
        $this->assertFileExists($incoming . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-21.xlsx');
        $this->assertDirectoryExists($incoming);
        $this->assertSame(1, $this->tableCount('report_imports', "status = 'success'"));
        $this->assertSame(1, $this->tableCount('report_imports', "status = 'failed'"));
        $this->assertSame(575, $this->tableCount('work_order_snapshots'));

        $this->exec('import_pending_reports');

        $this->assertExitError();
        $this->assertErrorContains('Ignorado por duplicidade: Relatorio_OS_2026-08-21.xlsx');
        $this->assertCount(1, $this->xlsxFiles('processed'));
        $this->assertCount(2, $this->xlsxFiles('error'));
        $this->assertSame(2, $this->tableCount('report_imports'));
        $this->assertSame(575, $this->tableCount('work_order_snapshots'));
        $this->assertFileExists($incoming . DIRECTORY_SEPARATOR . basename($realReport));
    }

    public function testOperationalFileRemainsInIncomingAndUsesHashToAvoidReimport(): void
    {
        $incoming = $this->temporaryRoot . DIRECTORY_SEPARATOR . 'incoming';
        mkdir($incoming, 0775, true);
        $operationalPath = $incoming . DIRECTORY_SEPARATOR . 'Relatorio_OS_ATUAL.xlsx';
        copy($this->realReportPath(), $operationalPath);
        touch($operationalPath, strtotime('2026-08-28 08:00:00'));

        $this->exec('import_pending_reports');

        $this->assertExitSuccess();
        $this->assertFileExists($operationalPath);
        $this->assertSame(1, $this->tableCount('report_imports', "status = 'success'"));
        $this->assertSame(575, $this->tableCount('work_order_snapshots'));
        $archives = $this->xlsxFiles('processed');
        $this->assertCount(1, $archives);
        $this->assertStringContainsString('Relatorio_OS_2026-08-28_', basename($archives[0]));
        $this->assertSame([], $this->filesDirectlyInside('staging'));

        $this->exec('import_pending_reports');

        $this->assertExitSuccess();
        $this->assertErrorContains('Ignorado por duplicidade: Relatorio_OS_ATUAL.xlsx');
        $this->assertSame(1, $this->tableCount('report_imports'));
        $this->assertSame(575, $this->tableCount('work_order_snapshots'));
        $this->assertCount(1, $this->xlsxFiles('processed'));
    }

    public function testImportsArbitraryTotvsCsvNamesByContentAndFileDate(): void
    {
        $incoming = $this->temporaryRoot . DIRECTORY_SEPARATOR . 'incoming';
        mkdir($incoming, 0775, true);
        $first = $incoming . DIRECTORY_SEPARATOR . 'sclxes70.csv';
        $this->writeValidCsv($first, '7001');
        touch($first, strtotime('2026-08-27 10:00:00'));

        $this->exec('import_pending_reports');

        $this->assertExitSuccess();
        $this->assertOutputContains('Importado: sclxes70.csv');
        $this->assertSame(1, $this->tableCount('report_imports', "file_name = 'sclxes70.csv' AND report_date = '2026-08-27'"));
        $this->assertFileExists($first);
        $this->assertDirectoryExists($incoming);

        $second = $incoming . DIRECTORY_SEPARATOR . 'sclxes71.csv';
        $this->writeValidCsv($second, '7002');
        touch($second, strtotime('2026-08-28 10:00:00'));

        $this->exec('import_pending_reports');

        $this->assertExitSuccess();
        $this->assertOutputContains('Importado: sclxes71.csv');
        $this->assertSame(1, $this->tableCount('report_imports', "file_name = 'sclxes71.csv' AND report_date = '2026-08-28'"));
        $this->assertSame(2, $this->tableCount('report_imports', "status = 'success'"));
        $this->assertFileExists($first);
        $this->assertFileExists($second);
        $this->assertDirectoryExists($incoming);
    }

    public function testEmptyOfficialDirectoryIsNeverRemoved(): void
    {
        $incoming = $this->temporaryRoot . DIRECTORY_SEPARATOR . 'incoming';
        mkdir($incoming, 0775, true);

        $this->exec('import_pending_reports');

        $this->assertExitSuccess();
        $this->assertOutputContains('Nenhum relatório CSV ou XLSX pendente encontrado.');
        $this->assertDirectoryExists($incoming);
    }

    public function testMissingOfficialDirectoryIsNotCreated(): void
    {
        $incoming = $this->temporaryRoot . DIRECTORY_SEPARATOR . 'missing-official';
        Configure::write('Pcm.reports.incoming', $incoming);

        try {
            (new ReportFileProcessor())->ensureDirectories();
            $this->fail('Era esperada uma falha para a pasta oficial inexistente.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('não existe', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($incoming);
    }

    public function testLocalStagingCannotBeConfiguredInsideOfficialDirectory(): void
    {
        $incoming = $this->temporaryRoot . DIRECTORY_SEPARATOR . 'incoming';
        mkdir($incoming, 0775, true);
        $unsafeStaging = $incoming . DIRECTORY_SEPARATOR . 'staging';
        Configure::write('Pcm.reports.staging', $unsafeStaging);

        try {
            (new ReportFileProcessor())->ensureDirectories();
            $this->fail('Era esperada uma falha para staging dentro da pasta oficial.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('não pode ficar dentro', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($unsafeStaging);
        $this->assertDirectoryExists($incoming);
    }

    private function realReportPath(): string
    {
        $external = dirname(ROOT) . DIRECTORY_SEPARATOR . 'relatorios_teste'
            . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-21.xlsx';
        if (is_file($external)) {
            return $external;
        }

        return ROOT . DIRECTORY_SEPARATOR . 'relatorios' . DIRECTORY_SEPARATOR . 'processados'
            . DIRECTORY_SEPARATOR . 'c7d77770b4515c86' . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-21.xlsx';
    }

    private function tableCount(string $table, string $where = '1 = 1'): int
    {
        $row = static::connection()->execute("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}")->fetch('assoc');

        return (int)$row['total'];
    }

    private function writeValidCsv(string $path, string $orderNumber): void
    {
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        fputcsv($handle, TotvsHeaderValidator::EXPECTED, ';', '"', '\\');
        $row = array_fill(0, count(TotvsHeaderValidator::EXPECTED), '');
        $row[0] = '1';
        $row[1] = $orderNumber;
        $row[3] = '28/08/2026';
        $row[5] = 'EQ-TESTE';
        $row[6] = 'Equipamento teste';
        $row[7] = 'CORMEC';
        $row[8] = 'CORRETIVA MECANICA';
        $row[10] = 'COR';
        $row[11] = 'MECANI';
        $row[12] = '4101002';
        $row[40] = 'Não';
        $row[44] = 'Liberado';
        fputcsv($handle, $row, ';', '"', '\\');
        fclose($handle);
    }

    /** @return list<string> */
    private function xlsxFiles(string $directory): array
    {
        $root = $this->temporaryRoot . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'xlsx') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function filesDirectlyInside(string $directory): array
    {
        $root = $this->temporaryRoot . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($root)) {
            return [];
        }

        return array_values(array_filter(
            glob($root . DIRECTORY_SEPARATOR . '*') ?: [],
            static fn(string $path): bool => is_file($path) || is_dir($path),
        ));
    }

    private function removeTemporaryTree(): void
    {
        if (!isset($this->temporaryRoot) || !is_dir($this->temporaryRoot)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->temporaryRoot);
    }
}
