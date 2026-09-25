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
    public const CATEGORIES = ['preventive' => 'Preventivas', 'corrective' => 'Corretivas', 'improvement' => 'Melhorias',
        'emergency' => 'Corretivas Emergenciais', 'scheduled' => 'Corretivas Programadas', 'opportunity' => 'Paradas por Oportunidade'];
    public const TYPES = ['preventive' => 'PRE', 'corrective' => 'COR', 'improvement' => 'MEL'];
    public const SERVICES = ['emergency' => ['COREME'], 'scheduled' => ['CORPRO'], 'opportunity' => ['MECOPO', 'ELECOP']];
    public const BACKLOG_AGES = ['0_7' => '0–7 dias', '8_15' => '8–15 dias', '16_30' => '16–30 dias',
        '31_60' => '31–60 dias', 'over_60' => '+60 dias', 'unknown' => 'Sem data válida', 'future' => 'Data futura'];
    public const FILTERS = ['filial', 'status', 'equipment', 'service', 'service_name', 'cost_center', 'maintenance_type', 'q', 'date_start', 'date_end', 'card', 'card_status', 'backlog_age'];
    public function __construct(private ?ProtheusRepository $repository = null, private readonly ?\Closure $diagnostic = null)
    {
    }

    public function load(string $area, array $query, bool $export = false, ?int $exportPage = null): array
    {
        if ($export) $query = array_replace($query, ['page' => $exportPage ?? 1, 'limit' => \App\Service\StreamingXlsxReport::BATCH_SIZE]);
        if (!preg_match('/^[A-Z0-9_-]{1,30}$/D', $area)) throw new InvalidArgumentException('Área inválida.');
        $filters = [];
        foreach (self::FILTERS as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > ($key === 'service_name' ? 255 : 100)) throw new InvalidArgumentException('Filtro inválido.');
            $filters[$key] = trim($value);
        }
        if (!in_array($filters['status'], ['', 'EM ABERTO', 'FECHADA'], true)) throw new InvalidArgumentException('Status inválido.');
        if (!in_array($filters['backlog_age'], ['', 'all', ...array_keys(self::BACKLOG_AGES)], true)) throw new InvalidArgumentException('Faixa de backlog inválida.');
        if (!in_array($filters['card'], ['', 'all', 'safra', 'offseason', ...array_keys(self::CATEGORIES)], true)
            || !in_array($filters['card_status'], ['', 'EM ABERTO', 'FECHADA'], true)) throw new InvalidArgumentException('Indicador inválido.');
        foreach (['date_start', 'date_end'] as $key) {
            if ($filters[$key] !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new InvalidArgumentException('Data inválida.');
            }
        }
        if ($filters['date_start'] !== '' && $filters['date_end'] !== '' && $filters['date_start'] > $filters['date_end']) throw new InvalidArgumentException('Período inválido.');
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,
            'max_range' => $export ? \App\Service\StreamingXlsxReport::BATCH_SIZE : 100]]);
        if ($page === false || $limit === false || $page > intdiv(PHP_INT_MAX, (int)$limit)) throw new InvalidArgumentException('Paginação inválida.');
        $result = ['available' => false, 'code' => $area, 'name' => MaintenanceAreasTable::FRIENDLY_NAMES[$area] ?? $area,
            'filters' => $filters, 'queried_at' => null, 'orders' => [], 'cards' => [], 'charts' => [],
            'page' => $page, 'limit' => $limit, 'has_more' => false, 'missing_start' => null];
        $params = array_diff_key($filters, array_flip(['date_start', 'date_end', 'card', 'card_status', 'backlog_age']));
        $params += ['area' => $area, 'cutoff' => str_replace('-', '', WorkOrderSnapshotsTable::OPERATIONAL_START)];
        if ($params['q'] !== '') $params['q'] = '%' . strtr($params['q'], ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~[']) . '%';
        $stage = 'connection';
        $repository = null;
        try {
            $selection = ['backlog_age' => $filters['backlog_age'], 'as_of' => (new DateTimeImmutable())->format('Y-m-d'),
                'status' => $filters['card_status'], 'type' => self::TYPES[$filters['card']] ?? '',
                'services' => self::SERVICES[$filters['card']] ?? [],
                'season' => in_array($filters['card'], ['safra', 'offseason'], true) ? $filters['card'] : ''];
            $repository = $this->repository ?? new ProtheusRepository(budgetSeconds: 10);
            $stage = 'repository';
            $data = $repository->sector($params, $page, $limit, $filters['date_start'], $filters['date_end'], $selection, $export);
            $stage = 'validation: aggregates / cards and classifications';
            $cards = array_fill_keys(ProtheusDashboardService::CARDS, 0);
            $breakdown = array_fill_keys(array_keys(self::CATEGORIES), ['open' => 0, 'closed' => 0]);
            $operational = ['total' => 0, 'open' => 0, 'closed' => 0];
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
                    $statusKey = $row['status'] === 'EM ABERTO' ? 'open' : 'closed';
                    $operational[$statusKey] += $quantity;
                    foreach (self::CATEGORIES as $category => $_label) {
                        $matches = isset(self::TYPES[$category]) ? $row['TJ_TIPO'] === self::TYPES[$category]
                            : in_array($row['TJ_SERVICO'], self::SERVICES[$category], true);
                        if ($matches) {
                            $breakdown[$category][$statusKey] += $quantity;
                            if ($statusKey === 'open') $cards[$category] += $quantity;
                        }
                    }
                    $cards[$season . ($row['status'] === 'EM ABERTO' ? '_open' : '_completed')] += $quantity;
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
            $stage = 'validation: aggregates / totals';
            if ($total === null) throw new RuntimeException('Resultado incompleto.');
            $operational['total'] = $total;
            if ($total !== $operational['open'] + $operational['closed']) throw new RuntimeException('Contagens inconsistentes.');
            $stage = 'validation: backlog / dimensions and totals';
            $backlog = ['total' => null, 'ages' => array_fill_keys(array_keys(self::BACKLOG_AGES), 0),
                'equipment' => [], 'costCenters' => [], 'maintenance' => [], 'as_of' => $selection['as_of']];
            foreach ($data['backlog'] as $row) {
                $quantity = (int)$row['quantity'];
                $dimension = $row['dimension'];
                if ($dimension === 'total') { $backlog['total'] = $quantity; continue; }
                if ($dimension === 'age') { $backlog['ages'][$row['age_bucket']] = $quantity; continue; }
                $label = match ($dimension) {
                    'equipment' => ($row['TJ_CODBEM'] ?: 'Sem código') . ' — ' . ($row['equipment_name'] ?? 'Sem nome'),
                    'costCenters' => $row['TJ_CCUSTO'] ?: 'Não informado',
                    'maintenance' => ['PRE' => 'Preventiva', 'COR' => 'Corretiva', 'MEL' => 'Melhoria'][$row['TJ_TIPO']] ?? ($row['TJ_TIPO'] ?: 'Não informado'),
                };
                $backlog[$dimension][] = ['label' => $label, 'quantity' => $quantity, 'branch' => $row['TJ_FILIAL'] ?? ''];
            }
            if ($backlog['total'] === null || $backlog['total'] !== array_sum($backlog['ages'])) {
                // Counts only: enough to diagnose the SQL result contract without exposing OS data.
                $dimensions = array_fill_keys(['total', 'age', 'equipment', 'costCenters', 'maintenance'], 0);
                $ageRowsSum = 0;
                foreach ($data['backlog'] as $row) {
                    if (isset($dimensions[$row['dimension']])) $dimensions[$row['dimension']]++;
                    if ($row['dimension'] === 'age') $ageRowsSum += (int)$row['quantity'];
                }
                throw new RuntimeException(sprintf(
                    'Backlog incompleto. total=%s; soma_faixas=%d; soma_linhas_age=%d; dimensoes=%s; faixas=%s.',
                    $backlog['total'] === null ? 'ausente' : (string)$backlog['total'],
                    array_sum($backlog['ages']), $ageRowsSum,
                    json_encode($dimensions, JSON_THROW_ON_ERROR), json_encode($backlog['ages'], JSON_THROW_ON_ERROR),
                ));
            }
            foreach ($charts as &$rows) foreach ($rows as &$row) $row['percentage'] = $total > 0 ? $row['quantity'] / $total * 100 : 0;
            unset($rows, $row);
            return array_replace($result, ['available' => true, 'queried_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'orders' => $data['orders'], 'cards' => $cards, 'breakdown' => $breakdown, 'operational' => $operational,
                'charts' => $charts, 'backlog' => $backlog, 'missing_start' => $missing, 'has_more' => $data['has_more']]);
        } catch (Throwable $exception) {
            if ($this->diagnostic !== null && PHP_SAPI === 'cli') {
                ($this->diagnostic)($exception, $stage === 'repository' ? $repository->sectorDiagnosticStage() : $stage);
            }
            return $result;
        }
    }
}
