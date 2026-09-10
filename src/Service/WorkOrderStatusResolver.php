<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Import\TotvsValueNormalizer;

final class WorkOrderStatusResolver
{
    public const VERSION = 4;
    public const CANCELLED = 'CANCELADA';
    public const COMPLETED = 'FECHADA';
    public const OPEN = 'EM ABERTO';

    public function __construct(private readonly TotvsValueNormalizer $normalizer = new TotvsValueNormalizer())
    {
    }

    public function resolve(mixed $situation, mixed $finished): string
    {
        if (in_array($this->normalizer->control($situation), ['cancelada', 'cancelado'], true)) {
            return self::CANCELLED;
        }
        if ($this->normalizer->control($finished) === 'sim') {
            return self::COMPLETED;
        }

        return self::OPEN;
    }
}
