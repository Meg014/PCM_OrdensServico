<?php
declare(strict_types=1);

namespace App\Service\Import;

use RuntimeException;

final class DuplicateReportException extends RuntimeException
{
    public function __construct(public readonly string $fileHash)
    {
        parent::__construct('Este arquivo já foi importado (hash duplicado).');
    }
}
