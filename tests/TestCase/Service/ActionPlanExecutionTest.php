<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Database\Driver\ProtheusReadOnly;
use App\Service\ActionPlanExecution;
use App\Service\ActionPlanExcelReport;
use App\Service\ActionPlanObservation;
use App\Service\ActionPlanService;
use App\Service\Protheus\ActionPlanExecutionQuery;
use App\Service\Protheus\ActionPlanQuery;
use App\Service\Protheus\ProtheusQueries;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class ActionPlanExecutionTest extends TestCase
{
    private const EXAMPLES = [
        '006889' => 'FOI TROCADO OS ROLAMENTOS 6309X6205.',
        '007356' => 'FOI FEITA A INSTALAÇÃO DAS CAMERAS DE SEGURANÇA DA TUBULAÇÃO.',
        '007324' => 'FOI FEITA A MANUTENÇÃO E LIMPEZA DO AR COND. LAB. SOJA SALA FRIA.',
        '007307' => 'FOI FEITA A LIMPEZA NA UTE NOVA.',
        '007325' => 'FOI FEITA A INSTALAÇÃO DA BB SAPO.',
    ];

    private function stage(string $number, string $text, int $record = 1): array
    {
        return ['TQ_FILIAL' => '01', 'TQ_ORDEM' => $number, 'TQ_PLANO' => '000000',
            'TQ_TAREFA' => '000118', 'TQ_SEQTARE' => '001', 'TQ_ETAPA' => '001247',
            'TQ_SEQETA' => '002', 'TQ_OK' => '', 'TQ_CODFUNC' => '000001',
            'stage_record' => $record, 'executed_observation' => $text];
    }

    public function testFiveConfirmedExamplesWithActualBatchSqlScopeDeletionAndBinaryCast(): void
    {
        self::assertTrue(ProtheusQueries::allows(ActionPlanExecutionQuery::SQL));
        self::assertFalse(ProtheusQueries::allows(ActionPlanExecutionQuery::SQL . '; DELETE FROM dbo.STQ010'));
        self::assertStringNotContainsString('STL010', ActionPlanExecutionQuery::SQL);
        self::assertDoesNotMatchRegularExpression('/TQ_OK\s*=/i', ActionPlanExecutionQuery::SQL);
        self::assertStringContainsString('CONVERT(varchar(max), q.TQ_OBSERVA)', ActionPlanExecutionQuery::SQL);
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE STQ010 (TQ_FILIAL TEXT,TQ_ORDEM TEXT,TQ_PLANO TEXT,TQ_TAREFA TEXT,
            TQ_ETAPA TEXT,TQ_SEQETA TEXT,TQ_SEQTARE TEXT,TQ_OK TEXT,TQ_CODFUNC TEXT,
            R_E_C_N_O_ INTEGER,TQ_OBSERVA BLOB,D_E_L_E_T_ TEXT)');
        $insert = $db->prepare("INSERT INTO STQ010 VALUES ('01',?,'000000','000118','001247','002','001',?,'000001',?,CAST(? AS BLOB),?)");
        $record = 0; $identities = [];
        foreach (self::EXAMPLES as $number => $text) {
            $insert->execute([$number, ++$record % 2 ? '' : 'XX', $record, $text . '  ', '']);
            $identities[] = ['branch' => '01', 'number' => $number];
        }
        $insert->execute(['006889', 'XX', 20, 'EXCLUÍDO', '*']);
        $insert->execute(['006889', '', 21, null, '']);
        $insert->execute(['006889', '', 22, " \t\r\n ", '']);
        $insert->execute(['999999', '', 23, 'FORA DO FILTRO', '']);
        $db->exec("INSERT INTO STQ010 VALUES ('02','006889','','','','','','','',24,CAST('OUTRA FILIAL' AS BLOB),'')");
        $identities[] = $identities[0]; // EXISTS must not multiply records for repeated identities.
        $sql = str_replace(['dbo.STQ010', 'CONVERT(varchar(max), q.TQ_OBSERVA)'],
            ['STQ010', 'CAST(q.TQ_OBSERVA AS TEXT)'], ActionPlanExecutionQuery::SQL);
        $sql = preg_replace("~OPENJSON\\(CAST\\(:identities AS nvarchar\\(max\\)\\)\\)\\s+WITH \\(branch varchar\\(100\\) '\\$.branch', number varchar\\(100\\) '\\$.number'\\)~",
            "(SELECT json_extract(value,'$.branch') branch, json_extract(value,'$.number') number FROM json_each(:identities))", $sql);
        $statement = $db->prepare($sql);
        $statement->execute(['identities' => json_encode($identities)]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(6, $rows); // Five observations plus one whitespace-only observation.
        $orders = array_map(static fn ($number) => ['branch' => '01', 'number' => $number], array_keys(self::EXAMPLES));
        $output = ActionPlanExecution::attach($orders, $rows);
        self::assertCount(5, $output);
        foreach ($output as $order) self::assertSame(self::EXAMPLES[$order['number']] . '  ', $order['executed_service']);
    }

    public function testMultipleStagesAreDeterministicAndDoNotDeduplicateDifferentStageContexts(): void
    {
        $text = "  Ação realizada: troca do óleo — peça nº 2.\r\nObservação legítima.  ";
        $first = $this->stage('006889', $text, 10);
        $second = array_replace($first, ['stage_record' => 11, 'TQ_SEQETA' => '003']);
        $blank = $this->stage('006889', " \t\n", 12);
        $orders = [['branch' => '01', 'number' => '006889'], ['branch' => '02', 'number' => '006889']];
        $a = ActionPlanExecution::attach($orders, [$second, $blank, $first, $first]);
        $b = ActionPlanExecution::attach($orders, [$first, $second]);
        self::assertSame($b, $a);
        self::assertSame(2, substr_count($a[0]['executed_service'], $text));
        self::assertLessThan(strpos($a[0]['executed_service'], 'Seq. etapa 003'), strpos($a[0]['executed_service'], 'Seq. etapa 002'));
        self::assertStringContainsString('Tarefa 000118', $a[0]['executed_service']);
        self::assertStringContainsString('Seq. tarefa 001', $a[0]['executed_service']);
        self::assertSame('', $a[1]['executed_service']);
        self::assertSame('006889', $a[0]['number']);
    }

    public function testServiceUsesTwoReadsAndKeepsExecutionWhitespaceWithoutMultiplyingOrders(): void
    {
        foreach ([false, true] as $empty) {
            $calls = [];
            $connection = $this->createMock(Connection::class);
            $connection->method('getDriver')->willReturn(new ProtheusReadOnly());
            $connection->method('execute')->willReturnCallback(function ($sql, $params) use (&$calls, $empty) {
                self::assertTrue(ProtheusQueries::allows($sql));
                $calls[] = [$sql, $params];
                if ($sql === ActionPlanQuery::sql()) {
                    $row = ['TJ_FILIAL' => '01', 'TJ_ORDEM' => '006889', 'TJ_TERMINO' => 'N', 'reference_date' => '2026-11-01',
                        'cost_center_name' => 'Fábrica', 'equipment_name' => 'Bem', 'description' => 'Abertura',
                        'service_name' => 'Corretiva'];
                    $rows = $empty ? [] : array_fill(0, 25, $row);
                } else {
                    self::assertSame(ActionPlanExecutionQuery::SQL, $sql);
                    self::assertSame([['branch' => '01', 'number' => '006889']], json_decode($params['identities'], true));
                    $rows = [$this->stage('006889', 'Execução acentuada.  ')];
                }
                $statement = $this->createMock(StatementInterface::class);
                $statement->method('fetchAll')->willReturn($rows);
                return $statement;
            });
            $output = (new ActionPlanService(new ProtheusRepository($connection)))->load([
                'date_start' => '2026-11-01', 'date_end' => '2026-11-09', 'unidade' => 'factory']);
            self::assertCount($empty ? 1 : 2, $calls);
            self::assertSame('factory', $calls[0][1]['unidade']);
            if (!$empty) {
                self::assertCount(1, $output);
                self::assertSame('Abertura', $output[0]['description']);
                self::assertSame('Execução acentuada.  ', $output[0]['executed_service']);
            } else self::assertSame([], $output);
        }
    }

    public function testExcelWritesTheFiveExamplesToMAndKeepsStatusLast(): void
    {
        $orders = []; $stages = [];
        foreach (self::EXAMPLES as $number => $text) {
            $orders[] = ['branch' => '01', 'number' => $number, 'sector' => 'Fábrica', 'equipment' => 'Bem',
                'date' => '2026-11-01', 'description' => "Abrir equipamento.\nferramentas limpas antes do serviço? ( ) sim ( ) não\nManter observação legítima.",
                'mechanics' => 'Responsável', 'service' => 'Corretiva', 'tasks' => '', 'status' => 'Aberta'];
            $stages[] = $this->stage($number, $text);
        }
        $output = ActionPlanExecution::attach($orders, $stages);
        $stream = (new ActionPlanExcelReport())->write($output);
        $book = IOFactory::load(stream_get_meta_data($stream)['uri']);
        try {
            $sheet = $book->getSheet(0);
            self::assertSame(1, $book->getSheetCount());
            self::assertSame('B7:N13', $sheet->getAutoFilter()->getRange());
            self::assertSame('Status', (string)$sheet->getCell('N7')->getValue());
            foreach ($output as $i => $order) {
                $row = $i + 9;
                self::assertSame($order['number'], (string)$sheet->getCell('K' . $row)->getValue());
                self::assertSame(self::EXAMPLES[$order['number']], (string)$sheet->getCell('M' . $row)->getValue());
                self::assertSame('Aberta', (string)$sheet->getCell('N' . $row)->getValue());
                self::assertSame("Abrir equipamento.\nManter observação legítima.", (string)$sheet->getCell('H' . $row)->getValue());
                self::assertStringContainsString('ferramentas limpas', $order['description']); // Cleaned only by Excel.
            }
            self::assertTrue($sheet->getStyle('M9')->getAlignment()->getWrapText());
        } finally { $book->disconnectWorksheets(); fclose($stream); }
    }

    public function testUnexpectedBranchAndInvalidUtf8FailRatherThanInventText(): void
    {
        self::assertSame('', ActionPlanObservation::executionForExcel(null));
        self::assertSame('', ActionPlanObservation::executionForExcel(" \r\n\t"));
        self::assertSame('Ação, peça Ø — nº 2.', ActionPlanObservation::executionForExcel('Ação, peça Ø — nº 2.'));
        try {
            ActionPlanExecution::attach([['branch' => '02', 'number' => '006889']], [$this->stage('006889', 'Texto')]);
            self::fail('Unexpected stage scope accepted');
        } catch (\DomainException $e) { self::assertStringContainsString('fora', $e->getMessage()); }
        $this->expectException(\DomainException::class);
        ActionPlanObservation::executionForExcel("\xFF");
    }
}
