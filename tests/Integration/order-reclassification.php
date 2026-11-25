<?php
declare(strict_types=1);

use App\Service\OrderReclassificationService;
use App\Service\OrderStreamingExport;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusDashboardService;
use App\Service\Protheus\ProtheusRepository;
use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Datasource\ConnectionManager;

/**
 * Opt-in, live SELECT regression for SQL Server and an isolated local PostgreSQL.
 * Provision an empty pcm_reclass_tests database and set PCM_RECLASS_TEST_PORT.
 * The test migrates only that database and rolls back its sample transfer and history.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap.php';

$port = filter_var(getenv('PCM_RECLASS_TEST_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if (!$port) throw new RuntimeException('Informe PCM_RECLASS_TEST_PORT para o PostgreSQL de teste local.');
ConnectionManager::drop('default');
ConnectionManager::setConfig('default', ['className' => Connection::class, 'driver' => Postgres::class,
    'host' => '127.0.0.1', 'port' => $port, 'database' => 'pcm_reclass_tests',
    'username' => getenv('PGUSER') ?: 'pcm_test', 'password' => getenv('PGPASSWORD') ?: '']);
$db = ConnectionManager::get('default');
(new \Migrations\Migrations())->migrate();
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$service = new OrderReclassificationService();
verify($service->activeMap() === [], 'O banco de teste deve começar sem reclassificações.');
$db->enableSavePoints(true);
$db->begin();
try {
    $actor = $db->execute("INSERT INTO users (nome,email,password,role,created,modified)
        VALUES ('Teste PCM',?,'inativo','ADMIN',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id",
        [uniqid('regression') . '@example.invalid'])->fetch()[0];
    $branch = '01';
    $destination = 'MOEND';
    $reason = 'Regressão de transferência e reversão em lote';
    $sample = (new ProtheusRepository())->findOrders(branch: $branch, limit: 500, export: true, area: 'MECANI');
    $numbers = array_column($sample['orders'], 'TJ_ORDEM');
    verify(count($numbers) === 500, 'A regressão de desempenho requer 500 O.S. reais de Mecânica.');
    $rows = $service->preview($branch, implode("\r\n", $numbers) . ';' . $numbers[0], $destination, $reason);
    verify(count($rows) === 500 && !array_filter(array_column($rows, 'issue')), 'Falha na prévia ou deduplicação.');
    $baseline = (new ProtheusDashboardService())->load([], false);
    verify($baseline['available'], 'TV indisponível antes da transferência.');
    $service->confirm(compact('rows', 'branch', 'destination', 'reason'), $numbers, (int)$actor);
    foreach ([true, false] as $analysis) {
        $dashboard = (new ProtheusDashboardService())->load([], $analysis);
        verify($dashboard['available'] && $dashboard['indicators'] === $baseline['indicators'], 'Falha no Geral/TV com 500 reclassificações.');
    }
    $number = $numbers[0];
    $original = ['filial' => $branch, 'os' => $number, 'area' => 'MECANI'];
    $target = array_replace($original, ['area' => $destination]);
    $listing = new OrderListingService();
    verify($listing->load($original)['total'] === 0 && $listing->load($target)['total'] === 1, 'Transferência incorreta entre setores.');
    $report = new OrderStreamingExport();
    $file = $report->orders('orders', $target, null, $report->generatedAt());
    try {
        verify($file['count'] === 1, 'Exportação incorreta.');
    } finally {
        fclose($file['stream']);
    }
    verify($service->preview($branch, $number, $destination, $reason)[0]['issue'] === 'Já reclassificada', 'Duplicidade não identificada.');
    $ids = array_column($db->execute('SELECT id FROM order_reclassifications ORDER BY id')->fetchAll('assoc'), 'id');
    try {
        $service->reverse([$ids[0], max($ids) + 1], $reason, (int)$actor);
        throw new RuntimeException('Lote obsoleto aceito.');
    } catch (DomainException) {
        verify(count($service->activeMap()) === 500, 'Reversão parcial.');
    }
    $service->reverse([array_shift($ids)], $reason, (int)$actor);
    $service->reverse($ids, $reason, (int)$actor);
    verify($service->activeMap() === [] && $listing->load($original)['total'] === 1, 'Área original não restaurada.');
    $events = $db->execute('SELECT COUNT(*) FROM order_reclassification_events WHERE user_id=? AND reason=?', [$actor, $reason])->fetch()[0];
    verify((int)$events === 1000, 'Histórico incompleto.');
    echo "OK — 500 transferências e reversões, Geral, TV, filtros, exportação e auditoria.\n";
} finally {
    $db->rollback();
}
