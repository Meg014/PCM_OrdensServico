<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\OrderProtheusService;
use App\Service\Protheus\ProtheusReaderInterface;
use PDOException;
use PHPUnit\Framework\TestCase;

final class OrderProtheusServiceTest extends TestCase
{
    private const SNAPSHOT = ['source_order_number' => '004368', 'branch_code' => '01', 'equipment_name' => 'PCM inalterado'];

    public function testLoadsOneDetailAndOneHistoryWithValidatedFields(): void
    {
        $reader = $this->createMock(ProtheusReaderInterface::class);
        $reader->expects(self::once())->method('findOrder')->with('004368', '01')->willReturn($this->order());
        $reader->expects(self::never())->method('findOrderIdentity');
        $reader->expects(self::once())->method('findEquipmentHistory')->with('MEL 80 115', '01', 1, 10)
            ->willReturn($this->history());
        $result = (new OrderProtheusService($reader))->load(self::SNAPSHOT);
        $detail = $result['detail'];
        self::assertSame('available', $detail['state']);
        self::assertSame('MOTOR ROSCA RO-02 - SILO 01', $detail['maintenance']['equipment_name']);
        self::assertSame('PREVENTIVA ELETRICA', $detail['maintenance']['service_name']);
        self::assertSame('2026-08-18', $detail['maintenance']['actual_end_date']);
        self::assertCount(2, $detail['labor']);
        self::assertSame('DAMIAO GONCALVES', $detail['labor'][0]['professional']);
        self::assertSame('008382', $detail['labor'][0]['code']);
        self::assertSame('1.00', $detail['labor'][0]['hours']);
        self::assertSame('H', $detail['labor'][0]['unit']);
        self::assertSame('09:30', $detail['labor'][0]['start_time']);
        self::assertSame('10:30', $detail['labor'][0]['end_time']);
        self::assertSame('2026-08-18', $detail['labor'][0]['end_date']);
        self::assertCount(2, $detail['materials']);
        self::assertSame(['002075', '000110'], array_column($detail['materials'], 'code'));
        self::assertSame('ROLAMENTO 6203 ZZ C3', $detail['materials'][1]['description']);
        self::assertNull($detail['materials'][1]['unit']); // Do not infer from B1_UM.
        self::assertSame('2026-08-18', $result['history']['orders'][0]['reference_date']);
        self::assertSame('L', $result['history']['orders'][0]['TJ_SITUACA']);
        $json = json_encode($result);
        foreach (['TJ_USUAINI', 'TJ_TIPO', 'CORRETIVA', 'password', 'TJ_OBSERVA', 'SQLSTATE'] as $excluded) {
            self::assertStringNotContainsString($excluded, $json);
        }
    }

    public function testFailureIsSanitizedAndDoesNotLoadHistory(): void
    {
        $reader = $this->createMock(ProtheusReaderInterface::class);
        $reader->expects(self::once())->method('findOrder')->willThrowException(new PDOException('SQLSTATE HYT00 server=secret user=secret password=secret'));
        $reader->expects(self::never())->method('findEquipmentHistory');
        $result = (new OrderProtheusService($reader))->load(self::SNAPSHOT);
        self::assertSame(['state' => 'unavailable', 'message' => OrderProtheusService::UNAVAILABLE], $result['detail']);
        self::assertNull($result['history']);
        self::assertStringNotContainsString('secret', json_encode($result));
    }

    public function testHistoryFailurePreservesSuccessfulDetail(): void
    {
        $reader = $this->createMock(ProtheusReaderInterface::class);
        $reader->method('findOrder')->willReturn($this->order());
        $reader->method('findEquipmentHistory')->willThrowException(new PDOException('internal'));
        $result = (new OrderProtheusService($reader))->load(self::SNAPSHOT);
        self::assertSame('available', $result['detail']['state']);
        self::assertSame('unavailable', $result['history']['state']);
        self::assertStringNotContainsString('internal', json_encode($result));
    }

    public function testPaginationDoesNotFetchAnyResourceDetails(): void
    {
        $reader = $this->createMock(ProtheusReaderInterface::class);
        $reader->expects(self::never())->method('findOrder');
        $reader->expects(self::once())->method('findOrderIdentity')->with('004368', '01')->willReturn($this->identity());
        $reader->expects(self::once())->method('findEquipmentHistory')->with('MEL 80 115', '01', 2, 10)->willReturn($this->history());
        $result = (new OrderProtheusService($reader))->load(self::SNAPSHOT, 'history', 2);
        self::assertNull($result['detail']);
        self::assertSame(2, $result['history']['page']);
    }

    public function testHistoricDetailMustBelongToSameEquipmentAndBranch(): void
    {
        foreach (['valid', 'equipment', 'branch'] as $scenario) {
            $reader = $this->createMock(ProtheusReaderInterface::class);
            $reader->expects(self::never())->method('findEquipmentHistory');
            $reader->method('findOrderIdentity')->willReturn($this->identity());
            $order = $this->order();
            $order['numero'] = '001234';
            if ($scenario === 'equipment') $order['dados_principais']['TJ_CODBEM'] = 'OUTRO BEM';
            if ($scenario === 'branch') $order['dados_principais']['TJ_FILIAL'] = '02';
            $reader->expects(self::once())->method('findOrder')->with('001234', '01')->willReturn($order);
            $result = (new OrderProtheusService($reader))->load(self::SNAPSHOT, 'detail', 1, '001234');
            self::assertSame($scenario === 'valid' ? 'available' : 'unavailable', $result['detail']['state']);
        }
    }

    public function testMissingOrderAndInvalidInputDoNotCauseExtraQueries(): void
    {
        $reader = $this->createMock(ProtheusReaderInterface::class);
        $reader->expects(self::once())->method('findOrder')->willReturn(null);
        $reader->expects(self::never())->method('findEquipmentHistory');
        $service = new OrderProtheusService($reader);
        self::assertSame('not_found', $service->load(self::SNAPSHOT)['detail']['state']);
        self::assertSame('unavailable', $service->load(self::SNAPSHOT, 'history', 0)['history']['state']);
        self::assertSame('unavailable', $service->load([])['detail']['state']);
    }

    private function identity(): array
    {
        return ['TJ_ORDEM' => '004368', 'TJ_FILIAL' => '01', 'TJ_CODBEM' => 'MEL 80 115'];
    }

    private function history(): array
    {
        return ['has_more' => true, 'orders' => [$this->identity() + [
            'TJ_SERVICO' => 'ELEPRE', 'service_name' => 'PREVENTIVA ELETRICA',
            'TJ_CODAREA' => 'ELE', 'TJ_SITUACA' => 'L', 'TJ_TERMINO' => 'S', 'reference_date' => '2026-08-18',
            'TJ_USUAINI' => 'must not reach the view',
        ]]];
    }

    private function order(): array
    {
        $labor = ['apontamento' => [
            'TL_TIPOREG' => 'M', 'TL_CODIGO' => '008382', 'TL_DTINICI' => '20260818',
            'TL_DTFIM' => '20260818', 'TL_HOINICI' => '09:30', 'TL_HOFIM' => '10:30',
            'TL_QUANTID' => '1.00', 'TL_UNIDADE' => 'H',
        ], 'profissional' => ['T1_NOME' => 'DAMIAO GONCALVES']];
        return [
            'numero' => '004368', 'dados_principais' => $this->identity() + [
                'TJ_SERVICO' => 'ELEPRE', 'TJ_TIPO' => 'COR', 'TJ_DTMRFIM' => '20260818',
                'TJ_USUAINI' => 'secret', 'TJ_OBSERVA' => 'binary',
            ],
            'descricao' => 'TROCA DOS ROLAMENTOS DO MOTOR',
            'equipamento' => ['T9_NOME' => 'MOTOR ROSCA RO-02 - SILO 01'],
            'servico' => ['T4_NOME' => 'PREVENTIVA ELETRICA'],
            'mao_de_obra' => [$labor, $labor],
            'materiais' => [
                ['apontamento' => ['TL_TIPOREG' => 'P', 'TL_CODIGO' => '002075', 'TL_UNIDADE' => 'UN', 'TL_QUANTID' => 1],
                    'produto' => ['B1_DESC' => 'ROLAMENTO 6203']],
                ['apontamento' => ['TL_TIPOREG' => 'P', 'TL_CODIGO' => '000110', 'TL_DTINICI' => 'invalid'],
                    'produto' => ['B1_DESC' => 'ROLAMENTO 6203 ZZ C3', 'B1_UM' => 'UN']],
            ],
            'outros_apontamentos' => [['TL_TIPOREG' => 'T'], ['TL_TIPOREG' => 'E']],
        ];
    }
}
