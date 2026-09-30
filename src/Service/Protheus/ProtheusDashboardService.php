<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use App\Service\PcmServiceClassifier;
use App\Model\Table\MaintenanceAreasTable;
use RuntimeException;

final class ProtheusDashboardService
{
    public const CARDS = ['safra_open', 'safra_completed', 'offseason_open', 'offseason_completed',
        'preventive', 'corrective', 'improvement', 'emergency', 'scheduled', 'opportunity'];
    public const FILTERS = ['filial', 'area', 'bem', 'servico', 'centro', 'tipo', 'situacao', 'termino'];

    public function __construct(private ?ProtheusRepository $repository = null)
    {
    }

    public function load(array $query = [], bool $includeAnalysis = true): array
    {
        $filters = [];
        foreach (self::FILTERS as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100) {
                throw new InvalidArgumentException('Filtros inválidos.');
            }
            $filters[$key] = trim($value);
        }
        $payload = ['available' => false, 'source' => 'Protheus', 'queried_at' => null,
            'filters' => $filters, 'record_count' => null, 'groups' => [],
            'indicators' => array_fill_keys(self::CARDS, null), 'screens' => [], 'analysis' => null, 'detail' => null];
        try {
            $repository = $this->repository ?? new ProtheusRepository(budgetSeconds: 5);
            $rows = $repository->dashboard($filters, true);
            $counts = array_fill_keys(self::CARDS, 0);
            $screens = ['general' => ['key' => 'general', 'title' => 'PCM - VISÃO GERAL'] + $counts];
            $classifier = new PcmServiceClassifier();
            $payload['record_count'] = 0;
            foreach ($rows as $row) {
                $payload['record_count'] += $row['quantity'];
                if ((int)$row['unconfirmed_count'] > 0) {
                    throw new RuntimeException('Status não confirmado.');
                }
                $eligible = (int)$row['open_count'] + (int)$row['closed_count'];
                if ($eligible > 0 && ((int)$row['service_matches'] !== 1 || $row['service_name'] === null)) {
                    throw new RuntimeException('Serviço indisponível ou ambíguo.');
                }
                $area = (string)$row['TJ_CODAREA'];
                $areaKey = trim($area) === '' ? null : 'area:' . $area;
                if ($areaKey !== null) {
                    $screens[$areaKey] ??= ['key' => $areaKey,
                        'title' => 'PCM - ' . mb_strtoupper(
                            MaintenanceAreasTable::FRIENDLY_NAMES[$area] ?? $area,
                        )] + $counts;
                }
                // classifySnapshot changes EMERGENCIAL/PROGRAMADA to OUTROS for non-COR;
                // neither result is ENTRESSAFRA, so the seasonal split is type-independent.
                $season = $classifier->classify($row['TJ_SERVICO'], $row['service_name']) === 'ENTRESSAFRA' ? 'offseason' : 'safra';
                $typeKey = ['PRE' => 'preventive', 'COR' => 'corrective', 'MEL' => 'improvement'][rtrim((string)($row['TJ_TIPO'] ?? ''), ' ')] ?? null;
                $serviceKey = ['COREME' => 'emergency', 'CORPRO' => 'scheduled',
                    'MECOPO' => 'opportunity', 'ELECOP' => 'opportunity'][rtrim((string)$row['TJ_SERVICO'], ' ')] ?? null;
                foreach ($areaKey === null ? ['general'] : ['general', $areaKey] as $key) {
                    $screens[$key][$season . '_open'] += (int)$row['open_count'];
                    $screens[$key][$season . '_completed'] += (int)$row['closed_count'];
                    if ($typeKey !== null) {
                        $screens[$key][$typeKey] += (int)$row['open_count'];
                    }
                    if ($serviceKey !== null) {
                        $screens[$key][$serviceKey] += (int)$row['open_count'];
                    }
                }
            }
            $payload['indicators'] = array_intersect_key($screens['general'], $counts);
            $general = $screens['general'];
            unset($screens['general']);
            $order = array_flip(array_keys(MaintenanceAreasTable::FRIENDLY_NAMES));
            uksort($screens, static fn ($a, $b) => [($order[substr($a, 5)] ?? PHP_INT_MAX), $a] <=> [($order[substr($b, 5)] ?? PHP_INT_MAX), $b]);
            $payload['screens'] = [$general, ...array_values($screens)];
            if ($includeAnalysis) {
                $payload['analysis'] = $this->analysis($repository->dashboard($filters));
                $detailQuery = [
                    'filial' => $filters['filial'], 'equipment' => $filters['bem'],
                    'service' => $filters['servico'], 'cost_center' => $filters['centro'],
                    'maintenance_type' => $filters['tipo'],
                    'status' => $filters['termino'] === 'N' ? 'EM ABERTO' : ($filters['termino'] === 'S' ? 'FECHADA' : ''),
                    'page' => $query['page'] ?? 1, 'limit' => $query['limit'] ?? 20,
                ];
                foreach (['service_name', 'q', 'date_start', 'date_end', 'card', 'card_status', 'backlog_age'] as $key) {
                    if (isset($query[$key])) $detailQuery[$key] = $query[$key];
                }
                if ($this->repository === null) {
                    $payload['detail'] = (new ProtheusSectorService($repository))->load($filters['area'], $detailQuery);
                    if (!$payload['detail']['available']) throw new RuntimeException('Detalhamento geral indisponível.');
                }
            }
            $payload['available'] = true;
            $payload['queried_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        } catch (Throwable) {
            $payload['record_count'] = null;
            $payload['groups'] = [];
            $payload['screens'] = [];
            $payload['indicators'] = array_fill_keys(self::CARDS, null);
        }

        return $payload;
    }

    private function analysis(array $rows): array
    {
        $result = ['total' => 0, 'equipment' => [], 'services' => [], 'costCenters' => [], 'maintenance' => [], 'sectors' => [],
            'status' => ['completed' => 0, 'open' => 0, 'canceled' => 0]];
        foreach ($rows as $row) {
            $code = rtrim((string)$row['code']);
            $quantity = (int)$row['quantity'];
            if ($row['dimension'] === 'total') {
                $result['total'] = $quantity;
            } elseif ($row['dimension'] === 'equipment') {
                $result['equipment'][] = ['code' => $code, 'name' => rtrim((string)$row['equipment_name']),
                    'branch' => rtrim((string)$row['branch']), 'quantity' => $quantity];
            } elseif ($row['dimension'] === 'cost_center') {
                $result['costCenters'][] = ['code' => $code, 'quantity' => $quantity];
            } elseif ($row['dimension'] === 'service') {
                $result['services'][] = ['code' => $code, 'name' => rtrim((string)$row['service_name']),
                    'branch' => rtrim((string)$row['branch']), 'quantity' => $quantity];
            } elseif ($row['dimension'] === 'type') {
                $label = ['COR' => 'Corretiva', 'PRE' => 'Preventiva', 'MEL' => 'Melhoria'][$code] ?? ($code ?: 'Sem tipo');
                $result['maintenance'][] = ['label' => $label, 'quantity' => $quantity];
            } elseif ($row['dimension'] === 'area') {
                $result['sectors'][] = ['code' => $code,
                    'label' => MaintenanceAreasTable::FRIENDLY_NAMES[$code] ?? ($code ?: 'Sem setor'), 'quantity' => $quantity];
            } elseif ($row['dimension'] === 'status' && array_key_exists($code, $result['status'])) {
                $result['status'][$code] = $quantity;
            }
        }
        foreach (['maintenance', 'sectors'] as $dimension) {
            if (array_sum(array_column($result[$dimension], 'quantity')) !== $result['total']) {
                throw new RuntimeException('Distribuição histórica incompleta.');
            }
        }
        if (array_sum($result['status']) !== $result['total']) {
            throw new RuntimeException('Situação histórica incompleta.');
        }

        return $result;
    }
}
