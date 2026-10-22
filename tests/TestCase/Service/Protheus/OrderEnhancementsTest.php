<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\EquipmentFilter;
use App\Service\Protheus\EquipmentLookupQueries;
use App\Service\Protheus\OrderDescriptionQueries;
use App\Service\Protheus\OrderDescriptions;
use App\Service\Protheus\OrderEntryExportService;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\Presentation\LaborDuration;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use App\Service\Protheus\ProtheusSectorQueries;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class OrderEnhancementsTest extends TestCase
{
    public function testDurationsCompareRecordedHoursAcrossDatesWithoutChangingThem(): void
    {
        $base = ['date' => '2026-09-21', 'end_date' => '2026-09-21', 'start_time' => '07:30', 'unit' => 'H'];
        foreach ([['08:00', '0.5', '30 min (0,50 h)'], ['08:15', '0.75', '45 min (0,75 h)'],
            ['08:30', '1', '1h (1,00 h)'], ['09:00', '1.5', '1h 30min (1,50 h)'],
            ['09:30', '2', '2h (2,00 h)'], ['08:35', '1.08', '1h 5min (1,08 h)']] as [$end, $quantity, $expected]) {
            $row = $base + ['end_time' => $end, 'hours' => $quantity];
            $result = LaborDuration::present($row);
            self::assertSame($expected, $result['hours_display']);
            self::assertNull($result['hours_note']);
            self::assertSame($quantity, $row['hours']);
        }
        $overnight = array_replace($base, ['start_time' => '23:30', 'end_date' => '2026-09-22', 'end_time' => '01:00', 'hours' => '1.5']);
        self::assertSame('1h 30min (1,50 h)', LaborDuration::present($overnight)['hours_display']);
        $threeAndHalf = LaborDuration::present($base + ['end_time' => '11:00', 'hours' => '3.5']);
        self::assertSame('3h 30min (3,50 h)', $threeAndHalf['hours_display']);
        self::assertNull($threeAndHalf['hours_note']);
        $different = LaborDuration::present($base + ['end_time' => '08:15', 'hours' => '0.5']);
        self::assertSame('Duração entre horários: 45 min (0,75 h)', $different['hours_display']);
        self::assertStringContainsString('Quantidade registrada: 0.5 H', $different['hours_note']);
        self::assertStringContainsString('Divergência', $different['hours_note']);
        self::assertStringContainsString('indisponível', LaborDuration::present($base + ['end_time' => '06:00', 'hours' => '2'])['hours_note']);
        self::assertSame(['hours_display' => '0.75', 'hours_note' => null],
            LaborDuration::present(['hours' => '0.75', 'unit' => 'UN']));
        self::assertStringContainsString('indisponível', LaborDuration::present(['unit' => 'H', 'hours' => '1'])['hours_note']);
        $unknownQuantity = LaborDuration::present($base + ['end_time' => '08:15', 'hours' => null]);
        self::assertStringContainsString('indisponível para comparação', $unknownQuantity['hours_note']);
        self::assertStringNotContainsString('Divergência', $unknownQuantity['hours_note']);
    }

    public function testOnlyRealDetailedSourcesAreUsedInPriorityOrder(): void
    {
        self::assertSame("Original\nLinha 2", OrderDescriptions::best("Original\nLinha 2\0", ['Apontamento'], 'Detalhe'));
        self::assertSame('Apontamento', OrderDescriptions::best(" \0", ['', 'Apontamento', 'Outro'], 'Detalhe'));
        self::assertSame('Detalhe', OrderDescriptions::best(null, [], 'Detalhe'));
        self::assertNull(OrderDescriptions::best(null, ['', "\xFF\x01", '...'], null));
        self::assertStringNotContainsString('T4_NOME', OrderDescriptionQueries::BATCH);
        self::assertStringNotContainsString('ST5010', OrderDescriptionQueries::BATCH);
        self::assertStringContainsString('T4_DESCRIC', OrderDescriptionQueries::BATCH);
        self::assertStringContainsString('l.TL_FILIAL = j.TJ_FILIAL', OrderDescriptionQueries::BATCH);
    }

    public function testEachLaborEntryKeepsItsOwnReadableDescription(): void
    {
        $entries = [];
        foreach (['Inspecionar motor', "Lubrificar\nrolamentos\0", null, "\xFF\x01"] as $index => $description) {
            $entries[] = ['profissional' => ['T1_NOME' => $index < 2 ? 'Ana' : 'José'],
                'apontamento' => ['R_E_C_N_O_' => 100 + $index, 'TL_FILIAL' => '01', 'TL_ORDEM' => '006452',
                    'TL_TIPOREG' => 'M', 'TL_CODIGO' => $index < 2 ? '000001' : '000002',
                    'pcm_entry_description' => $description]];
        }
        $supplement = (new OrderSupplementMapper())->map(['numero' => '006452', 'dados_principais' => ['TJ_FILIAL' => '01'],
            'descricao' => 'Descrição geral que não deve ser repetida nos apontamentos', 'mao_de_obra' => $entries]);
        $detail = (new \App\Service\Protheus\Presentation\OrderDetailPresenter())->present(
            ['source_order_number' => '006452', 'branch_code' => '01'], $supplement,
            \App\Service\Protheus\Presentation\Availability::Available,
        )['protheus'];
        self::assertSame(['Ana', 'Ana', 'José', 'José'], array_column($detail['labor'], 'professional'));
        self::assertSame(['Inspecionar motor', "Lubrificar\nrolamentos", null, null], array_column($detail['labor'], 'description'));
        self::assertCount(4, $detail['labor']);
        self::assertStringContainsString('CONVERT(VARCHAR(MAX), l.TL_OBSERVA) AS pcm_entry_description', ProtheusQueries::ENTRIES);
        self::assertStringContainsString('RTRIM(j.TJ_FILIAL) = :filial', ProtheusQueries::ENTRIES);
        self::assertStringContainsString('RTRIM(j.TJ_ORDEM) = :numero', ProtheusQueries::ENTRIES);
    }

    public function testDescriptionBatchPreservesIdentitiesRowsAndPrecedence(): void
    {
        $calls = [];
        $sources = [
            ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000001', 'order_description' => 'Original', 'entry_description' => 'Entrada', 'service_description' => 'Detalhe'],
            ['TJ_FILIAL' => '02', 'TJ_ORDEM' => '000001', 'order_description' => null, 'entry_description' => 'Entrada filial 02', 'service_description' => 'Detalhe'],
            ['TJ_FILIAL' => '02', 'TJ_ORDEM' => '000001', 'order_description' => null, 'entry_description' => 'Outra entrada', 'service_description' => 'Detalhe'],
            ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000002', 'order_description' => null, 'entry_description' => null, 'service_description' => 'Detalhe'],
        ];
        $repository = $this->repository([OrderDescriptionQueries::BATCH => $sources], $calls);
        $orders = [['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000001'], ['TJ_FILIAL' => '02', 'TJ_ORDEM' => '000001'],
            ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000002'], ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '000003']];
        $result = $repository->describeOrders($orders);
        self::assertCount(1, $calls);
        self::assertCount(4, $result);
        self::assertSame(['Original', 'Entrada filial 02', 'Detalhe', null], array_column($result, 'descricao'));
        self::assertSame(array_column($orders, 'TJ_ORDEM'), array_column($result, 'TJ_ORDEM'));
        self::assertCount(4, json_decode($calls[0][1]['identities'], true));
    }

    public function testActivityTextIsDeduplicatedWithoutLosingSourceDateOrProfessional(): void
    {
        $mapped = (new OrderSupplementMapper())->map(['description_sources' => [
            ['source' => 'O.S.', 'text' => "Inspecionar\nLubrificar"],
            ['source' => 'Apontamento', 'text' => "Inspecionar\nLubrificar", 'date' => '20260921', 'professional' => 'Ana'],
            ['source' => 'Serviço', 'text' => 'Outro texto'],
        ]]);
        self::assertCount(2, $mapped->descriptions);
        self::assertSame("Inspecionar\nLubrificar", $mapped->descriptions[0]['text']);
        self::assertStringContainsString('O.S.', $mapped->descriptions[0]['source']);
        self::assertStringContainsString('21/09/2026 · Ana', $mapped->descriptions[0]['source']);
    }

    public function testSelectedEquipmentKeepsExactIdentityOnPagesAndBothExports(): void
    {
        $query = ['equipment_q' => 'BOM 01 — Bomba', 'bem' => 'BOM 01', 'bem_filial' => '02', 'page' => '2'];
        foreach ([false, true] as $export) {
            $calls = [];
            $result = (new OrderListingService($this->repository([], $calls)))->load($query, $export, 3);
            self::assertTrue($result['available']);
            self::assertSame('02', $calls[0][1]['filial']);
            self::assertSame('BOM 01', $calls[0][1]['bem']);
            self::assertSame('02', $result['filters']['bem_filial']);
            self::assertSame('', $result['filters']['filial']);
            self::assertSame($export ? 2000 : 20, $calls[0][1]['offset']);
        }
        $calls = [];
        $result = (new OrderEntryExportService($this->repository([], $calls)))->loadGeneral($query, 2);
        self::assertTrue($result['available']);
        self::assertSame('02', $calls[0][1]['filial']);
        self::assertSame('BOM 01', $calls[0][1]['bem']);
        self::assertSame('', $calls[0][1]['nome_bem']);
        self::assertSame('', EquipmentFilter::normalize(['bem' => '', 'bem_filial' => '02', 'filial' => ''])['filial']);
    }

    public function testSubstringQueriesAreBoundedEscapedAndAllowlisted(): void
    {
        $calls = [];
        $repository = $this->repository([], $calls);
        $repository->suggestEquipment('bom%_[');
        self::assertSame('%BOM~%~_~[%', $calls[0][1]['term']);
        self::assertStringContainsString('TOP (20)', $calls[0][0]);
        self::assertStringContainsString('T9_CODBEM COLLATE', $calls[0][0]);
        self::assertStringContainsString('T9_NOME COLLATE', $calls[0][0]);
        foreach ([EquipmentLookupQueries::SEARCH, OrderDescriptionQueries::BATCH,
            EquipmentLookupQueries::withSubstring(ProtheusSectorQueries::page()),
            EquipmentLookupQueries::withSubstring(ProtheusSectorQueries::entriesPage(true))] as $sql) {
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM STJ010'));
        }
        foreach (['BOM 01', 'bomba'] as $term) {
            $calls = [];
            $result = (new OrderListingService($this->repository([], $calls)))->load(['equipment_q' => $term]);
            self::assertTrue($result['available']);
            self::assertSame('%' . mb_strtoupper($term) . '%', $calls[0][1]['nome_bem']);
            self::assertStringContainsString('UPPER(j.TJ_CODBEM) LIKE f.nome_bem', $calls[0][0]);
        }
    }

    private function repository(array $responses, array &$calls): ProtheusRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
        $connection->method('execute')->willReturnCallback(function ($sql, $params, $types) use ($responses, &$calls) {
            self::assertTrue(ProtheusQueries::allows($sql));
            $calls[] = [$sql, $params, $types];
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($responses[$sql] ?? []);
            return $statement;
        });

        return new ProtheusRepository($connection);
    }
}
