<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Core\Configure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class PcmTimeFormatter
{
    private DateTimeZone $displayTimezone;

    public function __construct(?string $timezone = null)
    {
        $this->displayTimezone = new DateTimeZone(
            $timezone ?? (string)Configure::read('App.displayTimezone', 'America/Sao_Paulo'),
        );
    }

    public function format(?DateTimeInterface $value, string $pattern = 'd/m/Y H:i', string $empty = '—'): string
    {
        if ($value === null) {
            return $empty;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->displayTimezone)
            ->format($pattern);
    }
}
