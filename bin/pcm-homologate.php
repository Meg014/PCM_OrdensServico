<?php
declare(strict_types=1);

use App\Service\PcmPeriod;
use App\Service\Protheus\PcmReconciliationQueries;
use App\Service\Protheus\ProtheusDashboardService;
use App\Service\Protheus\ProtheusRepository;
use App\Service\Protheus\ProtheusSectorService;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusQueries;

require dirname(__DIR__) . '/vendor/autoload.php';
if ($argc < 3) {
    fwrite(STDERR, "Uso: php bin/pcm-homologate.php AAAA-MM-DD AAAA-MM-DD [--live]\n");
    exit(1);
}
$filters = ['date_start' => $argv[1], 'date_end' => $argv[2]];
PcmPeriod::fromFilters($filters);
$asOf = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$params = array_fill_keys(['filial', 'area', 'bem', 'servico', 'centro', 'tipo', 'situacao', 'termino', 'unidade'], '');
$params += ['cutoff' => '20260101', 'dashboard_start' => $argv[1], 'dashboard_end' => $argv[2], 'as_of' => $asOf,
    'date_start' => $argv[1], 'date_end' => $argv[2], 'nome_bem' => '', 'analitico' => '', 'centro_modo' => ''];
$literal = static fn(array $match): string => "N'" . str_replace("'", "''", $params[$match[1]]) . "'";
$sql = "-- Janela tecnica informada na linha de comando; nao define calendario agricola.\n"
    . "-- Somente SELECT. Mesmo universo dos indicadores da TV, incluindo areas vazias no geral.\n"
    . preg_replace_callback('/:([a-z_]+)/', $literal, PcmReconciliationQueries::totals()) . ";\n\n"
    . "-- Inconsistencias: apenas consulta, sem substituir datas ausentes.\n"
    . preg_replace_callback('/:([a-z_]+)/', $literal, PcmReconciliationQueries::QUALITY) . ";\n\n"
    . "-- Emitidas: somente Data de origem, incluindo status nao cancelados; nao equivale a concluidas.\n"
    . preg_replace_callback('/:([a-z_]+)/', $literal, ProtheusQueries::orders(false, false, false, true, false, false, true)) . ";\n";
$directory = dirname(__DIR__) . '/docs/sql';
if (!is_dir($directory)) mkdir($directory, 0775, true);
$path = $directory . '/pcm-tv-reconciliation.sql';
file_put_contents($path, $sql);
echo "SQL preparado: {$path}\n";
if (!in_array('--live', $argv, true)) exit(0);

require dirname(__DIR__) . '/config/bootstrap.php';
$stage = 'reconciliation_select';
try {
    $data = (new ProtheusRepository(budgetSeconds: 25))->reconciliation($filters, $asOf);
    $stage = 'tv_select';
    $payload = (new ProtheusDashboardService(new ProtheusRepository(budgetSeconds: 25)))->load($filters, false);
    if (!$payload['available']) throw new RuntimeException('TV indisponivel para comparar.');
    $expected = [];
    foreach ($data['totals'] as $row) {
        if ((int)$row['identity_count'] > 1 || (int)$row['service_matches'] !== 1 || (int)$row['unconfirmed_count'] > 0) {
            throw new RuntimeException('Populacao ambigua: nao homologar totais.');
        }
        $card = ($row['season'] === 'ENTRESSAFRA' ? 'offseason' : 'safra')
            . ($row['status'] === 'FECHADA' ? '_completed' : '_open');
        $keys = $row['area'] === '' ? ['general'] : ['general', 'area:' . $row['area']];
        foreach ($keys as $key) $expected[$key][$card] = ($expected[$key][$card] ?? 0) + (int)$row['quantity'];
    }
    $differences = [];
    foreach ($payload['screens'] as $screen) {
        foreach (['safra_open', 'safra_completed', 'offseason_open', 'offseason_completed'] as $card) {
            $wanted = $expected[$screen['key']][$card] ?? 0;
            if ($wanted !== $screen[$card]) $differences[] = ['screen' => $screen['key'], 'card' => $card,
                'sql' => $wanted, 'tv' => $screen[$card]];
        }
        unset($expected[$screen['key']]);
    }
    foreach ($expected as $key => $counts) $differences[] = ['missing_screen' => $key, 'sql' => $counts];
    $stage = 'dashboard_sector_search_exports';
    $dashboard = (new ProtheusDashboardService(new ProtheusRepository(budgetSeconds: 25)))->load($filters, true);
    if (!$dashboard['available'] || $dashboard['indicators'] !== $payload['indicators']) {
        $differences[] = ['dashboard' => 'indisponivel ou diferente da TV'];
    }
    foreach ($payload['screens'] as $screen) {
        $area = $screen['key'] === 'general' ? '' : substr($screen['key'], 5);
        $sector = (new ProtheusSectorService(new ProtheusRepository(budgetSeconds: 25)))->load($area, $filters);
        if (!$sector['available']) {
            $differences[] = ['sector_unavailable' => $screen['key']];
            continue;
        }
        foreach (['safra_open', 'safra_completed', 'offseason_open', 'offseason_completed'] as $card) {
            if ($sector['cards'][$card] !== $screen[$card]) $differences[] = ['sector' => $screen['key'], 'card' => $card,
                'tv' => $screen[$card], 'sector_count' => $sector['cards'][$card]];
        }
    }
    $searchFilters = ['period_start' => $argv[1], 'period_end' => $argv[2], 'situacao' => 'L', 'termino' => 'S'];
    $search = (new OrderListingService(new ProtheusRepository(budgetSeconds: 25)))->load($searchFilters);
    $closed = $payload['indicators']['safra_completed'] + $payload['indicators']['offseason_completed'];
    if (!$search['available'] || $search['total'] !== $closed) $differences[] = ['search_completed' => $search['total'], 'tv_completed' => $closed];
    $export = (new OrderListingService(new ProtheusRepository(budgetSeconds: 25)))->load($searchFilters, true, 1);
    $pageNumbers = array_column($search['orders'], 'TJ_ORDEM');
    $exportNumbers = array_column(array_slice($export['orders'], 0, count($pageNumbers)), 'TJ_ORDEM');
    if (!$export['available'] || $pageNumbers !== $exportNumbers) $differences[] = ['export' => 'pagina diverge da pesquisa'];
    $issued = (new OrderListingService(new ProtheusRepository(budgetSeconds: 25)))->load($filters);
    if (!$issued['available'] || $issued['total'] !== $data['issued_count']) {
        $differences[] = ['issued_sql' => $data['issued_count'], 'issued_search' => $issued['total']];
    }
    $stage = 'full_xlsx_export';
    $workbook = (new \App\Service\OrderStreamingExport())->orders('orders', $searchFilters, null,
        new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')));
    $target = fopen(dirname(__DIR__) . '/tmp/pcm-homologation-completed.xlsx', 'wb');
    rewind($workbook['stream']);
    stream_copy_to_stream($workbook['stream'], $target);
    fclose($target);
    fclose($workbook['stream']);
    if ($workbook['count'] !== $closed) $differences[] = ['xlsx_count' => $workbook['count'], 'tv_completed' => $closed];
    $report = ['available' => true, 'period' => $filters, 'as_of' => $asOf, 'differences' => $differences,
        'tv_indicators' => $payload['indicators'], 'sql_totals' => $data['totals'],
        'sector_screens_checked' => count($payload['screens']), 'search_completed' => $search['total'],
        'export_prefix_matches' => $pageNumbers === $exportNumbers,
        'issued_by_origin' => $data['issued_count'],
        'xlsx_completed_count' => $workbook['count'],
        'quality_count' => count($data['quality']), 'quality' => $data['quality']];
} catch (Throwable $error) {
    $message = preg_replace('/\nQuery:.*$/s', '', $error->getMessage());
    $configuration = \Cake\Datasource\ConnectionManager::getConfig('protheus');
    foreach (['username', 'password', 'host', 'database'] as $key) {
        if (!empty($configuration[$key])) $message = str_replace((string)$configuration[$key], '[redacted]', $message);
    }
    $report = ['available' => false, 'period' => $filters, 'stage' => $stage,
        'exception' => get_class($error), 'diagnostic' => $message,
        'pending' => 'Consulta nao concluida; nao declarar equivalencia com Protheus.'];
}
file_put_contents(dirname(__DIR__) . '/tmp/pcm-homologation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
unset($report['quality']);
unset($report['sql_totals']);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
exit($report['available'] && $report['differences'] === [] ? 0 : 1);
