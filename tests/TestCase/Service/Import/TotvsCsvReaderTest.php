<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\TotvsCsvReader;
use App\Service\Import\TotvsHeaderValidator;
use App\Service\Import\TotvsRowMapper;
use Cake\TestSuite\TestCase;

final class TotvsCsvReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totvs-' . bin2hex(random_bytes(6)) . '.csv';
        $handle = fopen('php://temp', 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, "RelatÃ³rio TOTVS\r\nParÃ¢metros da consulta\r\nGerado para teste\r\n");
        fputcsv($handle, TotvsHeaderValidator::EXPECTED, ';', '"', '\\');
        foreach ([['1001', 'Liberada', 'NÃ£o'], ['1002', 'Liberada', 'Sim'], ['1003', 'Cancelada', 'NÃ£o']] as [$number, $situation, $finished]) {
            $row = array_fill(0, count(TotvsHeaderValidator::EXPECTED), '');
            $row[0] = '1';
            $row[1] = $number;
            $row[3] = '28/08/2026';
            $row[5] = 'EQ-TESTE';
            $row[6] = 'Equipamento teste';
            $row[7] = 'CORMEC';
            $row[8] = 'Corretiva mecÃ¢nica';
            $row[10] = 'COR';
            $row[11] = 'MECANI';
            $row[12] = '4101002';
            $row[40] = $finished;
            $row[44] = $situation;
            fputcsv($handle, $row, ';', '"', '\\');
        }
        rewind($handle);
        $utf8 = stream_get_contents($handle);
        fclose($handle);
        file_put_contents($this->path, mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'));
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testReadsWindows1252ReportWithFullSchema(): void
    {
        $report = (new TotvsCsvReader())->read($this->path);

        $this->assertSame('CSV', $report['sheet_name']);
        $this->assertCount(57, $report['headers']);
        $this->assertCount(3, $report['rows']);
        $this->assertSame(5, $report['rows'][0]['source_row_number']);
        $this->assertStringContainsString('Windows-1252', implode(' ', $report['warnings']));
    }

    public function testStatusMappingUsesSituationAndFinishedColumns(): void
    {
        $report = (new TotvsCsvReader())->read($this->path);
        $mapper = new TotvsRowMapper();
        $statuses = array_map(
            static fn(array $row): string => $mapper->map($row['values'], $row['source_row_number'])['treated_status'],
            $report['rows'],
        );

        $this->assertSame(['EM ABERTO', 'FECHADA', 'CANCELADA'], $statuses);
    }
}
