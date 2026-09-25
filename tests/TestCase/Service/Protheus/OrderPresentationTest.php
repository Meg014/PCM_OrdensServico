<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\Presentation\Availability;
use App\Service\Protheus\Presentation\EquipmentHistoryPage;
use App\Service\Protheus\Presentation\OrderDetailPresenter;
use App\Service\Protheus\Presentation\OrderSupplement;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OrderPresentationTest extends TestCase
{
    public function testDefaultAndUnavailablePreservePcmAndHideSupplement(): void
    {
        $date = new DateTimeImmutable('2026-08-18 09:30:00');
        $snapshot = [
            'source_order_number' => '004893', 'branch_code' => '01',
            'equipment_name' => 'Nome atual PCM', 'maintenance_type' => 'Atual PCM',
            'general_actual_start' => $date, 'raw_payload' => ['internal' => 'never expose'],
        ];
        $presenter = new OrderDetailPresenter();
        $supplement = new OrderSupplement('004893', '01', 'Descrição adicional');
        $default = $presenter->present($snapshot, $supplement);
        self::assertSame('disabled', $default['protheus']['state']);
        self::assertNull($default['protheus']['description']);
        self::assertSame($date, $default['pcm']['general_actual_start']);
        self::assertSame('Nome atual PCM', $default['pcm']['equipment_name']);
        self::assertArrayNotHasKey('raw_payload', $default['pcm']);
        $fallback = $presenter->present($snapshot, $supplement, Availability::Unavailable);
        self::assertSame($default['pcm'], $fallback['pcm']);
        self::assertSame([], $fallback['protheus']['labor']);
        self::assertSame('Detalhes do Protheus temporariamente indisponíveis.', $fallback['protheus']['message']);
        foreach ([new OrderSupplement('004368', '01'), new OrderSupplement('004893', '02'), null] as $wrong) {
            self::assertSame('unavailable', $presenter->present($snapshot, $wrong, Availability::Available)['protheus']['state']);
        }
    }

    public function testOfflineMappingKeepsMultipleEntriesWithoutGuessingFieldsOrTypes(): void
    {
        $supplement = (new OrderSupplementMapper())->map([
            'numero' => '004893', 'dados_principais' => [
                'TJ_FILIAL' => '01 ', 'TJ_DTORIGI' => '20260923',
            ],
            'descricao' => 'LAVAR TODOS 3 FILTRO COM SODA', 'host' => 'internal',
            'mao_de_obra' => [
                ['apontamento' => ['TL_TIPOREG' => 'M ', 'TL_CODIGO' => '008382 ']],
                ['apontamento' => ['TL_TIPOREG' => 'M', 'TL_CODIGO' => '008382']],
                ['apontamento' => ['TL_TIPOREG' => 'M', 'TL_CODIGO' => '009999']],
                ['apontamento' => ['TL_TIPOREG' => 'T', 'TL_CODIGO' => 'unknown']],
            ],
            'materiais' => [
                ['apontamento' => ['TL_TIPOREG' => 'P', 'TL_CODIGO' => '002075']],
                ['apontamento' => ['TL_TIPOREG' => 'P', 'TL_CODIGO' => '000110']],
                ['apontamento' => ['TL_TIPOREG' => 'E', 'TL_CODIGO' => 'ELE']],
            ],
        ]);
        $result = (new OrderDetailPresenter())->present(
            ['source_order_number' => '004893', 'branch_code' => '01'],
            $supplement,
            Availability::Available,
        );
        self::assertCount(3, $result['protheus']['labor']);
        self::assertSame(['008382', '008382', '009999'], array_column($result['protheus']['labor'], 'code'));
        self::assertSame(['002075', '000110'], array_column($result['protheus']['materials'], 'code'));
        self::assertNull($result['protheus']['labor'][0]['hours']);
        self::assertNull($result['protheus']['materials'][0]['used_date']);
        self::assertSame('2026-09-23', $result['protheus']['origin_date']);
        self::assertStringNotContainsString('internal', json_encode($result));
    }

    public function testOriginDateInvalidOrNullValuesAreUnavailable(): void
    {
        $mapper = new OrderSupplementMapper();
        foreach ([null, '', '20260230', '2026-09-24 19:33:00'] as $invalid) {
            $supplement = $mapper->map(['numero' => '1', 'dados_principais' => [
                'TJ_FILIAL' => '01', 'TJ_DTORIGI' => $invalid,
            ]]);
            self::assertNull($supplement->originDate);
        }
    }

    public function testNormalizedContractCanCarryValidatedNamesDatesAndDecimalQuantities(): void
    {
        $supplement = new OrderSupplement('004368', '01', labor: [[
            'professional' => 'Profissional de teste', 'code' => '000001', 'date' => '2026-08-18',
            'start_time' => '09:30', 'end_time' => '10:30', 'hours' => '1.00', 'password' => 'secret',
        ]], materials: [[
            'code' => '002075', 'description' => 'ROLAMENTO 6203', 'quantity' => '1.0000',
            'unit' => 'UN', 'used_date' => '2026-08-18', 'used_time' => null,
        ]]);
        $result = (new OrderDetailPresenter())->present(
            ['source_order_number' => '004368', 'branch_code' => '01'], $supplement, Availability::Available,
        );
        self::assertSame('1.00', $result['protheus']['labor'][0]['hours']);
        self::assertSame('1.0000', $result['protheus']['materials'][0]['quantity']);
        self::assertNull($result['protheus']['materials'][0]['used_time']);
        self::assertArrayNotHasKey('password', $result['protheus']['labor'][0]);
    }

    public function testHistoryPreservesProviderOrderAndOnlyLinksResolvedLocalIds(): void
    {
        $orders = [
            ['number' => '004893', 'branch' => '01', 'reference_date' => '2026-08-18', 'pcm_snapshot_id' => 42],
            ['number' => '004368', 'branch' => '01', 'reference_date' => '2026-08-17', 'pcm_snapshot_id' => null],
        ];
        $disabled = new EquipmentHistoryPage('FTR 30 001', $orders, true);
        self::assertSame([], $disabled->present()['orders']);
        self::assertFalse($disabled->present()['has_more']);
        $page = new EquipmentHistoryPage('FTR 30 001', $orders, true, Availability::Available);
        $result = $page->present();
        self::assertSame(['004893', '004368'], array_column($result['orders'], 'number'));
        self::assertSame(['_name' => 'pcm-order', 'id' => 42], $result['orders'][0]['detail_route']);
        self::assertNull($result['orders'][1]['detail_route']);
        self::assertTrue($result['has_more']);
    }
}
