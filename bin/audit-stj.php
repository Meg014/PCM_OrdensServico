<?php
declare(strict_types=1);

use App\Service\Import\TotvsCsvReader;
use App\Service\Import\TotvsRowMapper;
use App\Service\OrderAuditFlags;
use App\Service\PcmServiceClassifier;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php bin/audit-stj.php arquivo.csv [AAAA-MM-DD]\n");
    exit(1);
}
$asOf = new DateTimeImmutable($argv[2] ?? 'today', new DateTimeZone('America/Sao_Paulo'));
$source = (new TotvsCsvReader())->read($argv[1]);
$mapper = new TotvsRowMapper();
$classifier = new PcmServiceClassifier();
$counts = ['orders' => 0, 'offseason' => 0, 'safra' => 0, 'cancelled' => 0,
    'open' => 0, 'completed' => 0, 'duplicates' => 0, 'future_origin' => 0, 'future_general_actual_end' => 0];
$seen = $flags = $periods = $findings = [];
foreach ($source['rows'] as $row) {
    $order = $mapper->map($row['values'], $row['source_row_number']);
    $key = json_encode([$order['branch_code'], $order['source_order_number']], JSON_THROW_ON_ERROR);
    if (isset($seen[$key])) {
        $counts['duplicates']++;
        continue;
    }
    $seen[$key] = true;
    $counts['orders']++;
    $season = $classifier->classify($order['service_code'], $order['service_name'], $order['branch_code']) === 'ENTRESSAFRA'
        ? 'offseason' : 'safra';
    $counts[$season]++;
    $status = ['CANCELADA' => 'cancelled', 'EM ABERTO' => 'open', 'FECHADA' => 'completed'][$order['treated_status']];
    $counts[$status]++;
    foreach (['origin_date' => 'future_origin', 'general_actual_end' => 'future_general_actual_end'] as $field => $counter) {
        if ($order[$field] !== null && $order[$field]->format('Y-m-d') > $asOf->format('Y-m-d')) {
            $counts[$counter]++;
        }
    }
    // Calendar year is an audit dimension, never an inferred agricultural period.
    $year = $order['maintenance_planned_start']?->format('Y') ?? 'sem_data';
    $periods[$year][$season][$status] = ($periods[$year][$season][$status] ?? 0) + 1;
    $issues = OrderAuditFlags::forOrder($order, $asOf);
    foreach ($issues as $type => $message) {
        $flags[$type] = ($flags[$type] ?? 0) + 1;
    }
    if ($issues !== []) {
        $findings[] = ['branch' => $order['branch_code'], 'number' => $order['source_order_number'],
            'source_row' => $row['source_row_number'], 'service_code' => $order['service_code'],
            'service_original' => $order['service_name'], 'area_original' => $order['maintenance_area_code'],
            'status' => $order['treated_status'], 'issues' => $issues];
    }
}
ksort($periods);
echo json_encode(['as_of' => $asOf->format('Y-m-d'), 'sha256' => hash_file('sha256', $argv[1]),
    'counts' => $counts, 'flags' => $flags, 'planned_calendar_years' => $periods, 'findings' => $findings],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
