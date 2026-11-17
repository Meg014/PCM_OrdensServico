<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\OrderStreamingExport;
use App\Service\PcmPeriod;
use App\Service\Protheus\OrderDates;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OrderDatesTest extends TestCase
{
    public function test005810DisplayAndExportAgreeWithDetailsWithoutGeneralEnd(): void
    {
        $row = ['TJ_ORDEM' => '005810', 'TJ_FILIAL' => '01', 'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'S',
            'TJ_DTMRINI' => '20261028', 'TJ_HOMRINI' => '07:30',
            'TJ_DTMRFIM' => '20261028', 'TJ_HOMRFIM' => '17:00', 'TJ_DTPRFIM' => '        '];
        $original = $row;
        $mapper = new OrderSupplementMapper();
        $detail = $mapper->map(['numero' => '005810', 'dados_principais' => $row]);
        self::assertSame('2026-10-28', $detail->maintenance['actual_start_date']);
        self::assertSame('07:30', $detail->maintenance['actual_start_time']);
        self::assertSame('2026-10-28', $detail->maintenance['actual_end_date']);
        self::assertSame('17:00', $detail->maintenance['actual_end_time']);
        self::assertNull($detail->maintenance['general_actual_end_date']);
        self::assertSame($detail->maintenance['actual_end_date'], $mapper->date(OrderDates::pair($row, 'actual_end')[0]));
        $columns = (new ReflectionMethod(OrderStreamingExport::class, 'orderColumns'))->invoke(new OrderStreamingExport(), 'orders');
        self::assertSame(['20261028', '17:00'], $columns['actual_end'][3]($row));
        self::assertSame($original, $row);
    }

    public function testMaintenanceDatesNeverFallBackToGeneralDatesAndIndicatorsKeepTheirOwnRule(): void
    {
        self::assertSame(['', ''], OrderDates::pair(['TJ_DTPRFIM' => '20261117', 'TJ_HOPRFIM' => '10:00'], 'actual_end'));
        self::assertSame(['20261117', '10:00'], OrderDates::pair([
            'TJ_DTMRFIM' => '20250101', 'TJ_HOMRFIM' => '08:00', 'TJ_DTPRFIM' => '20261117', 'TJ_HOPRFIM' => '10:00',
        ], 'general_actual_end'));
        self::assertStringContainsString('TJ_DTPRFIM', PcmPeriod::sqlDate());
        self::assertStringNotContainsString('TJ_DTMRFIM', PcmPeriod::sqlDate());
    }
}
