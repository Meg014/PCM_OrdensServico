<?php
declare(strict_types=1);

namespace App\Service\Import;

use RuntimeException;

final class TotvsReportReader
{
    /** Creates the format dispatcher with both supported readers. */
    public function __construct(
        private readonly TotvsWorkbookReader $workbookReader = new TotvsWorkbookReader(),
        private readonly TotvsCsvReader $csvReader = new TotvsCsvReader(),
    ) {
    }

    /** Dispatches supported report formats while preserving one normalized row contract. */
    public function read(string $path, string $sheetName): array
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => $this->workbookReader->read($path, $sheetName),
            'csv' => $this->csvReader->read($path),
            default => throw new RuntimeException('Formato não suportado. Use CSV ou XLSX.'),
        };
    }
}
