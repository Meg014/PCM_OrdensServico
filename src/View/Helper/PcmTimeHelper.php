<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Service\PcmTimeFormatter;
use Cake\View\Helper;
use DateTimeInterface;

final class PcmTimeHelper extends Helper
{
    private PcmTimeFormatter $formatter;

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->formatter = new PcmTimeFormatter();
    }

    public function format(?DateTimeInterface $value, string $pattern = 'd/m/Y H:i', string $empty = '—'): string
    {
        return $this->formatter->format($value, $pattern, $empty);
    }
}
