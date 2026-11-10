<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\Protheus\ActionPlanExecutionQuery;
use App\Service\Protheus\Presentation\Availability;
use App\Service\Protheus\Presentation\OrderDetailPresenter;
use App\Service\Protheus\Presentation\OrderSupplementMapper;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

final class OrderExecutionTest extends TestCase
{
    public function testSharedStageSourcePreservesTextAndDeduplicatesRecords(): void
    {
        $text = "Troca da peça.\nObservação <b>literal</b>  ";
        $stage = ['TQ_FILIAL' => '01', 'TQ_ORDEM' => '006889', 'stage_record' => '1',
            'TQ_TAREFA' => '000111', 'TQ_ETAPA' => '001247', 'executed_observation' => $text];
        $detail = $this->detail([$stage, $stage]);
        self::assertSame('006889', $detail['number']);
        self::assertSame('Descrição da abertura', $detail['original_description']);
        self::assertTrue($detail['executed_service_available']);
        self::assertSame($text, $detail['executed_service']);
    }

    public function testEmptyAndUnavailableSourcesRemainDistinct(): void
    {
        self::assertSame('', $this->detail([])['executed_service']);
        self::assertTrue($this->detail([])['executed_service_available']);
        $detail = $this->detail(null);
        self::assertSame('available', $detail['state']);
        self::assertFalse($detail['executed_service_available']);
        self::assertStringNotContainsString('secret', json_encode($detail));
    }

    private function detail(?array $stages): array
    {
        $reads = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriver')->willReturn(new ProtheusReadOnly([]));
        $connection->method('execute')->willReturnCallback(function ($sql, $params) use ($stages, &$reads) {
            if ($sql === ActionPlanExecutionQuery::SQL) {
                $reads++;
                self::assertSame([['branch' => '01', 'number' => '006889']], json_decode($params['identities'], true));
                if ($stages === null) throw new \RuntimeException('secret connection error');
            }
            $rows = match ($sql) {
                ProtheusQueries::ORDER_BRANCH => [['TJ_FILIAL' => '01', 'TJ_ORDEM' => '006889',
                    'TJ_CODBEM' => '', 'TJ_SERVICO' => '', 'pcm_descricao' => 'Descrição da abertura']],
                ActionPlanExecutionQuery::SQL => $stages,
                default => [],
            };
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        $order = (new ProtheusRepository($connection))->findOrder('006889', '01');
        self::assertSame(1, $reads);
        return (new OrderDetailPresenter())->present(
            ['source_order_number' => '006889', 'branch_code' => '01'],
            (new OrderSupplementMapper())->map($order), Availability::Available,
        )['protheus'];
    }
}
