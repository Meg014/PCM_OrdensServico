<?php
declare(strict_types=1);
namespace App\Service\Protheus;

use App\Model\Table\MaintenanceAreasTable;
use App\Model\Table\WorkOrderSnapshotsTable;
use App\Service\PcmServiceClassifier;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProtheusSectorService
{
    public const FILTERS = ['filial', 'status', 'equipment', 'service', 'service_name', 'cost_center', 'maintenance_type', 'q', 'date_start', 'date_end'];
    public function __construct(private ?ProtheusRepository $repository = null)
    {
    }

    public function load(string $area, array $query): array
    {
        if (!preg_match('/^[A-Z0-9_-]{1,30}$/D', $area)) throw new InvalidArgumentException('Área inválida.');
        $filters = [];
        foreach (self::FILTERS as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > ($key === 'service_name' ? 255 : 100)) throw new InvalidArgumentException('Filtro inválido.');
            $filters[$key] = trim($value);
        }
        if (!in_array($filters['status'], ['', 'EM ABERTO', 'FECHADA'], true)) throw new InvalidArgumentException('Status inválido.');
        foreach (['date_start', 'date_end'] as $key) {
            if ($filters[$key] !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new InvalidArgumentException('Data inválida.');
            }
        }
        if ($filters['date_start'] !== '' && $filters['date_end'] !== '' && $filters['date_start'] > $filters['date_end']) throw new InvalidArgumentException('Período inválido.');
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false) throw new InvalidArgumentException('Paginação inválida.');
        $result = ['available' => false, 'code' => $area, 'name' => MaintenanceAreasTable::FRIENDLY_NAMES[$area] ?? $area,
            'filters' => $filters, 'queried_at' => null, 'orders' => [], 'cards' => [], 'charts' => [],
            'page' => $page, 'limit' => $limit, 'has_more' => false, 'missing_start' => null];
        $params = array_diff_key($filters, array_flip(['date_start', 'date_end']));
        $params += ['area' => $area, 'cutoff' => str_replace('-', '', WorkOrderSnapshotsTable::OPERATIONAL_START)];
        if ($params['q'] !== '') $params['q'] = '%' . strtr($params['q'], ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~[']) . '%';
        try {
            $data = ($this->repository ?? new ProtheusRepository(budgetSeconds: 10))->sector($params, $page, $limit, $filters['date_start'], $filters['date_end']);
            $cards = array_fill_keys(ProtheusDashboardService::CARDS, 0);
            $charts = array_fill_keys(['status', 'maintenance', 'equipment', 'services', 'costCenters'], []);
            $total = null;
            $missing = 0;
            $classifier = new PcmServiceClassifier();
            foreach ($data['aggregates'] as $row) {
                $dimension = $row['dimension'];
                $quantity = (int)$row['quantity'];
                if ($dimension === 'total') { $total = $quantity; $missing = (int)$row['missing_start']; continue; }
                if ($dimension === 'cards') {
                    if ($row['service_name'] === null) throw new RuntimeException('Cadastro de serviço ausente.');
                    $season = $classifier->classify($row['TJ_SERVICO'], $row['service_name']) === 'ENTRESSAFRA' ? 'offseason' : 'safra';
                    $cards[$season . ($row['status'] === 'EM ABERTO' ? '_open' : '_completed')] += $quantity;
                    if ($row['status'] === 'EM ABERTO') {
                        $type = ['PRE' => 'preventive', 'COR' => 'corrective', 'MEL' => 'improvement'][$row['TJ_TIPO']] ?? null;
                        $service = ['COREME' => 'emergency', 'CORPRO' => 'scheduled'][$row['TJ_SERVICO']] ?? null;
                        if ($type !== null) $cards[$type] += $quantity;
                        if ($service !== null) $cards[$service] += $quantity;
                    }
                    continue;
                }
                [$key, $label] = match ($dimension) {
                    'status' => [$row['status'], $row['status']],
                    'maintenance' => [$row['TJ_TIPO'], ['PRE' => 'Preventivas', 'COR' => 'Corretivas', 'MEL' => 'Melhorias'][$row['TJ_TIPO']] ?? $row['TJ_TIPO']],
                    'equipment' => [$row['TJ_CODBEM'], $row['TJ_CODBEM'] . ' — ' . ($row['equipment_name'] ?? 'Sem nome')],
                    'services' => [$row['TJ_SERVICO'], $row['TJ_SERVICO'] . ' — ' . ($row['service_name'] ?? 'Sem nome')],
                    'costCenters' => [$row['TJ_CCUSTO'], $row['TJ_CCUSTO']],
                };
                $branch = $row['TJ_FILIAL'] ?? '';
                $charts[$dimension][] = ['key' => $key ?? '', 'label' => ($label ?: 'Não informado') . ($branch !== '' ? ' · filial ' . $branch : ''),
                    'branch' => $branch, 'quantity' => $quantity];
            }
            if ($total === null) throw new RuntimeException('Resultado incompleto.');
            foreach ($charts as &$rows) foreach ($rows as &$row) $row['percentage'] = $total > 0 ? $row['quantity'] / $total * 100 : 0;
            unset($rows, $row);
            return array_replace($result, ['available' => true, 'queried_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'orders' => $data['orders'], 'cards' => $cards, 'charts' => $charts, 'missing_start' => $missing, 'has_more' => $data['has_more']]);
        } catch (Throwable) {
            return $result;
        }
    }
}
