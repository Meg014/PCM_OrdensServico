<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\TotvsCsvReader;
use App\Service\Import\TotvsRowMapper;
use Cake\TestSuite\TestCase;

final class TotvsCsvReaderTest extends TestCase
{
    public function testReadsCurrentWindows1252ReportWithFullSchema(): void
    {
        $path = dirname(ROOT) . DIRECTORY_SEPARATOR . 'relatorios_teste'
            . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-28.csv';
        $this->assertFileExists($path);

        $report = (new TotvsCsvReader())->read($path);

        $this->assertSame('CSV', $report['sheet_name']);
        $this->assertCount(57, $report['headers']);
        $this->assertCount(4976, $report['rows']);
        $this->assertSame(4, $report['rows'][0]['source_row_number']);
        $this->assertStringContainsString('Windows-1252', implode(' ', $report['warnings']));
    }

    public function testStatusCountsIgnoreActualStart(): void
    {
        $path = dirname(ROOT) . DIRECTORY_SEPARATOR . 'relatorios_teste'
            . DIRECTORY_SEPARATOR . 'Relatorio_OS_2026-08-28.csv';
        $report = (new TotvsCsvReader())->read($path);
        $mapper = new TotvsRowMapper();
        $counts = [];
        foreach ($report['rows'] as $row) {
            $status = $mapper->map($row['values'], $row['source_row_number'])['treated_status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $this->assertSame(466, $counts['EM ABERTO']);
        $this->assertSame(3715, $counts['FECHADA']);
        $this->assertSame(795, $counts['CANCELADA']);
    }
}
