<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\PcmTimeFormatter;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

final class PcmTimeFormatterTest extends TestCase
{
    public function testConvertsUtcInstantToSaoPauloWithoutMutatingSource(): void
    {
        $utc = new DateTimeImmutable('2026-08-25 00:51:00', new DateTimeZone('UTC'));

        $formatted = (new PcmTimeFormatter('America/Sao_Paulo'))->format($utc, 'd/m/Y \à\s H:i');

        $this->assertSame('24/08/2026 às 21:51', $formatted);
        $this->assertSame('2026-08-25 00:51:00 UTC', $utc->format('Y-m-d H:i:s T'));
    }

    public function testReturnsPlaceholderForMissingTimestamp(): void
    {
        $this->assertSame('—', (new PcmTimeFormatter('America/Sao_Paulo'))->format(null));
    }
}
