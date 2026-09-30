<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\TotvsHeaderValidator;
use App\Service\Import\TotvsWorkbookReader;
use Cake\TestSuite\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class TotvsWorkbookReaderTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testReadsExpectedTotvsSheet(): void
    {
        $path = $this->workbook('sclxd280');

        $result = (new TotvsWorkbookReader())->read($path);

        $this->assertCount(57, $result['headers']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(2, $result['rows'][0]['source_row_number']);
        $this->assertSame('sclxd280', $result['sheet_name']);
        $this->assertSame([], $result['warnings']);
    }

    public function testAcceptsSingleRenamedSheetAfterHeaderValidation(): void
    {
        $path = $this->workbook('sclxdra0');

        $result = (new TotvsWorkbookReader())->read($path);

        $this->assertSame('sclxdra0', $result['sheet_name']);
        $this->assertCount(57, $result['headers']);
        $this->assertCount(1, $result['rows']);
        $this->assertStringContainsString('foi aceita', implode(' ', $result['warnings']));
    }

    private function workbook(string $sheetName): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'totvs-' . bin2hex(random_bytes(6)) . '.xlsx';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray(TotvsHeaderValidator::EXPECTED, null, 'A1');
        $row = array_fill(0, count(TotvsHeaderValidator::EXPECTED), '');
        $row[0] = '1';
        $row[1] = '1001';
        $sheet->fromArray($row, null, 'A2');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->paths[] = $path;

        return $path;
    }
}
