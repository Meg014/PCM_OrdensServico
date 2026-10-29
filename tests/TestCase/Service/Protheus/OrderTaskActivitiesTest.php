<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\OrderDescriptionQueries;
use App\Service\Protheus\OrderTaskQueries;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use App\Service\Protheus\Presentation\OrderTaskActivities;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class OrderTaskActivitiesTest extends TestCase
{
    private function row(array $extra = []): array
    {
        return $extra + ['TL_FILIAL' => '01', 'TL_ORDEM' => '006264', 'TL_PLANO' => '000000',
            'TL_SEQRELA' => '1', 'TL_SEQMAN' => '0', 'TL_SEQTARE' => '1', 'TL_TAREFA' => '000117',
            'TL_ETAPA' => '001992', 'task_description' => 'Checklist', 'stage_description' => 'Realizar checklist'];
    }

    public function testMultipleTasksAndStagesDeduplicateAppointmentsAndKeepTheirContexts(): void
    {
        $rows = [$this->row(), $this->row(), $this->row(['TL_SEQRELA' => '2']),
            $this->row(['TL_ETAPA' => '002000', 'TL_SEQTARE' => '2', 'stage_description' => 'Outra etapa']),
            $this->row(['TL_TAREFA' => '000111', 'task_description' => 'Manutenção corretiva', 'TL_ETAPA' => ''])];
        $data = OrderTaskActivities::present($rows, '01', '006264', true);
        self::assertSame('unavailable', $data['planning_state']);
        self::assertSame('available', $data['recorded_state']);
        self::assertCount(2, $data['tasks']);
        self::assertSame(['001992', '002000'], array_column($data['tasks'][0]['stages'], 'code'));
        self::assertCount(2, $data['tasks'][0]['stages'][0]['contexts']);
        self::assertCount(3, $data['tasks'][0]['contexts']);
        self::assertSame([], $data['tasks'][1]['stages']);
    }

    public function testSameTaskInDifferentPlansKeepsSeparateContexts(): void
    {
        $data = OrderTaskActivities::present([$this->row(), $this->row(['TL_PLANO' => '000005'])], '01', '006264', true);
        self::assertCount(2, $data['tasks']);
        self::assertSame(['000000', '000005'], array_column($data['tasks'], 'plan'));
    }

    public function testUnknownTaskDoesNotAcquireAnUnrelatedTask(): void
    {
        $data = OrderTaskActivities::present([$this->row(['TL_TAREFA' => '', 'task_description' => null])], '01', '006264', true);
        self::assertNull($data['tasks'][0]['code']);
        self::assertNull($data['tasks'][0]['description']);
        self::assertSame('001992', $data['tasks'][0]['stages'][0]['code']);
    }

    public function testEmptyAndUnavailableSourcesDoNotClaimThatPlanningIsEmpty(): void
    {
        foreach ([true, false] as $available) {
            $data = OrderTaskActivities::present([], '01', '006341', $available);
            self::assertSame('unavailable', $data['planning_state']);
            self::assertSame($available ? 'available' : 'unavailable', $data['recorded_state']);
            self::assertSame([], $data['tasks']);
        }
    }

    public function testDifferentBranchOrOrderRejectsTheOptionalPopulation(): void
    {
        foreach ([['TL_FILIAL' => '02'], ['TL_ORDEM' => '006342']] as $other) {
            $data = OrderTaskActivities::present([$this->row(), $this->row($other)], '01', '006264', true);
            self::assertSame('unavailable', $data['recorded_state']);
            self::assertSame([], $data['tasks']);
        }
    }

    public function testLongMultilineDescriptionsAndNoFixedRecordLimit(): void
    {
        $long = str_repeat("Descrição longa <script>literal</script>\n", 100);
        $rows = [];
        for ($i = 0; $i < 1500; $i++) $rows[] = $this->row(['TL_ETAPA' => str_pad((string)$i, 6, '0', STR_PAD_LEFT), 'stage_description' => $long]);
        $data = OrderTaskActivities::present($rows, '01', '006264', true);
        self::assertCount(1, $data['tasks']);
        self::assertCount(1500, $data['tasks'][0]['stages']);
        self::assertSame(rtrim($long), $data['tasks'][0]['stages'][1499]['description']);
    }

    public function testCatalogVariantsAreReadOnlyAndBoundToExactOrderAndBranch(): void
    {
        foreach ([false, true] as $tasks) foreach ([false, true] as $stages) {
            $sql = OrderTaskQueries::recorded($tasks, $stages);
            self::assertTrue(ProtheusQueries::allows($sql));
            self::assertFalse(ProtheusQueries::allows($sql . '; DELETE FROM dbo.STL010'));
            self::assertStringContainsString('l.TL_FILIAL = CAST(:filial AS VARCHAR(100))', $sql);
            self::assertStringContainsString('l.TL_ORDEM = CAST(:numero AS VARCHAR(100))', $sql);
            self::assertSame($tasks, str_contains($sql, 'FROM dbo.TT9010'));
            self::assertSame($stages, str_contains($sql, 'FROM dbo.TPA010'));
            self::assertStringNotContainsString('FROM dbo.ST5010', $sql);
            self::assertStringNotContainsString('TOP (', $sql);
            self::assertStringContainsString("TRY_CONVERT(BIGINT, NULLIF(l.TL_SEQTARE, ''))", $sql);
        }
    }

    public function testDeniedCatalogsStillExposeRecordedCodesAndNeverImplyCompletePlanning(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly([]));
        $connection->method('execute')->willReturnCallback(function ($sql) {
            self::assertTrue(ProtheusQueries::allows($sql));
            $rows = match ($sql) {
                ProtheusQueries::ORDER_BRANCH => [['TJ_FILIAL' => '01', 'TJ_ORDEM' => '006264', 'TJ_CODBEM' => 'ROTA05', 'TJ_SERVICO' => 'PREVEN', 'pcm_descricao' => 'Original']],
                OrderDescriptionQueries::PERMISSIONS => [['task_catalog_select' => 0, 'stage_catalog_select' => 0, 'maintenance_tasks_select' => 0]],
                OrderTaskQueries::recorded(false, false) => [$this->row(['task_description' => null, 'stage_description' => null])],
                default => [],
            };
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        $order = (new ProtheusRepository($connection))->findOrder('006264', '01');
        $supplement = (new OrderSupplementMapper())->map($order);
        self::assertSame('Original', $supplement->description);
        self::assertSame('unavailable', $supplement->taskActivities['planning_state']);
        self::assertSame('available', $supplement->taskActivities['recorded_state']);
        self::assertSame('001992', $supplement->taskActivities['tasks'][0]['stages'][0]['code']);
        self::assertNull($supplement->taskActivities['tasks'][0]['stages'][0]['description']);
        self::assertSame([], $supplement->labor);
        self::assertSame([], $supplement->materials);
    }

    public function testRecordedQueryFailureKeepsTheOriginalDetailAvailable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly([]));
        $connection->method('execute')->willReturnCallback(function ($sql) {
            if ($sql === OrderTaskQueries::recorded()) throw new \RuntimeException('private source unavailable');
            $rows = match ($sql) {
                ProtheusQueries::ORDER_BRANCH => [['TJ_FILIAL' => '01', 'TJ_ORDEM' => '006264', 'TJ_CODBEM' => 'ROTA05', 'TJ_SERVICO' => 'PREVEN', 'pcm_descricao' => 'Original preservado']],
                OrderDescriptionQueries::PERMISSIONS => [['task_catalog_select' => 1, 'stage_catalog_select' => 1]],
                default => [],
            };
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        $order = (new ProtheusRepository($connection))->findOrder('006264', '01');
        $supplement = (new OrderSupplementMapper())->map($order);
        $detail = (new \App\Service\Protheus\Presentation\OrderDetailPresenter())->present(
            ['source_order_number' => '006264', 'branch_code' => '01'], $supplement,
            \App\Service\Protheus\Presentation\Availability::Available,
        )['protheus'];
        self::assertSame('available', $detail['state']);
        self::assertSame('Original preservado', $detail['original_description']);
        self::assertSame('unavailable', $detail['task_activities']['recorded_state']);
        self::assertSame([], $detail['task_activities']['tasks']);
        self::assertStringNotContainsString('private source', json_encode($detail));
    }
}
