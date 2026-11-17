<?php
declare(strict_types=1);

use App\Service\PcmPeriod;
use App\Service\Protheus\PcmReconciliationQueries;
use App\Service\Protheus\ProtheusDashboardService;
use App\Service\Protheus\ProtheusRepository;
use App\Model\Table\WorkOrderSnapshotsTable;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/bootstrap.php';

$period = null;
$filters = [];
$asOf = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$repository = new ProtheusRepository(budgetSeconds: 25);
$fields = $repository->completionFields();
$data = $repository->reconciliation($filters, $asOf);
$baseline = (new ProtheusRepository(budgetSeconds: 25))->dashboard([], true, true);
$tv = (new ProtheusDashboardService(new ProtheusRepository(budgetSeconds: 25)))->load([], false);
if (!$tv['available']) throw new RuntimeException('TV indisponível; não homologar.');
$expected = [];
foreach ($data['totals'] as $row) {
    if ((int)$row['identity_count'] !== 1 || (int)$row['service_matches'] !== 1 || (int)$row['unconfirmed_count'] !== 0) {
        throw new RuntimeException('Identidade/cadastro/status ambíguo; não homologar.');
    }
    $card = ($row['season'] === 'ENTRESSAFRA' ? 'offseason' : 'safra')
        . ($row['status'] === 'FECHADA' ? '_completed' : '_open');
    foreach ($row['area'] === '' ? ['general'] : ['general', 'area:' . $row['area']] as $key) {
        $expected[$key][$card] = ($expected[$key][$card] ?? 0) + (int)$row['quantity'];
    }
}
$differences = [];
foreach ($tv['screens'] as $screen) {
    foreach (['safra_open', 'offseason_open', 'safra_completed', 'offseason_completed'] as $card) {
        if ($screen[$card] !== ($expected[$screen['key']][$card] ?? 0)) {
            $differences[] = ['screen' => $screen['key'], 'card' => $card, 'tv' => $screen[$card],
                'sql' => $expected[$screen['key']][$card] ?? 0];
        }
    }
    unset($expected[$screen['key']]);
}
foreach ($expected as $key => $counts) $differences[] = ['missing_screen' => $key, 'sql' => $counts];
$openBefore = array_sum(array_column($baseline, 'open_count'));
$openAfter = $tv['indicators']['safra_open'] + $tv['indicators']['offseason_open'];
if ($openBefore !== $openAfter) $differences[] = ['open_before' => $openBefore, 'open_after' => $openAfter];
$params = array_fill_keys(['filial', 'area', 'bem', 'servico', 'centro', 'tipo', 'situacao', 'termino', 'unidade'], '');
$params += ['cutoff' => str_replace('-', '', WorkOrderSnapshotsTable::OPERATIONAL_START),
    'dashboard_start' => '', 'dashboard_end' => ''];
$sql = preg_replace_callback('/:([a-z_]+)/', static fn(array $m): string => "N'" . str_replace("'", "''", $params[$m[1]]) . "'",
    PcmReconciliationQueries::totals(false));
file_put_contents(dirname(__DIR__) . '/docs/sql/pcm-tv-default-cycle.sql',
    "-- Sem filtro de periodo: fechadas por termino S, excluindo canceladas. Abertas preservadas. Somente SELECT.\n"
    . PcmReconciliationQueries::COMPLETION_FIELDS . ";\n\n" . $sql . ";\n");
$report = ['period' => $period, 'fields' => $fields, 'indicators' => $tv['indicators'],
    'screens_checked' => count($tv['screens']), 'open_before' => $openBefore, 'open_after' => $openAfter,
    'missing_completion' => array_sum(array_column($data['quality'], 'missing_completion')), 'differences' => $differences];
file_put_contents(dirname(__DIR__) . '/tmp/tv-default-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
exit($differences === [] ? 0 : 1);
