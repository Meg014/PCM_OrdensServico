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
        'preventive', 'corrective', 'improvement', 'emergency', 'scheduled'];
    public const FILTERS = ['filial', 'area', 'bem', 'servico', 'centro', 'tipo', 'situacao', 'termino'];

    public function __construct(private ?ProtheusRepository $repository = null)
    {
    }

    public function load(array $query = []): array
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
            'indicators' => array_fill_keys(self::CARDS, null), 'screens' => []];
        try {
            $rows = ($this->repository ?? new ProtheusRepository(budgetSeconds: 5))->dashboard($filters, true);
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
                $areaKey = 'area:' . $area;
                $screens[$areaKey] ??= ['key' => $areaKey,
                    'title' => 'PCM - ' . mb_strtoupper(MaintenanceAreasTable::FRIENDLY_NAMES[$area] ?? ($area ?: 'ÁREA EM BRANCO'))] + $counts;
                // classifySnapshot changes EMERGENCIAL/PROGRAMADA to OUTROS for non-COR;
                // neither result is ENTRESSAFRA, so the seasonal split is type-independent.
                $season = $classifier->classify($row['TJ_SERVICO'], $row['service_name']) === 'ENTRESSAFRA' ? 'offseason' : 'safra';
                $typeKey = ['PRE' => 'preventive', 'COR' => 'corrective', 'MEL' => 'improvement'][rtrim((string)($row['TJ_TIPO'] ?? ''), ' ')] ?? null;
                $serviceKey = ['COREME' => 'emergency', 'CORPRO' => 'scheduled'][rtrim((string)$row['TJ_SERVICO'], ' ')] ?? null;
                foreach (['general', $areaKey] as $key) {
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
}
