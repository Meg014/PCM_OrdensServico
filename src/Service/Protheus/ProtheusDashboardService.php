<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class ProtheusDashboardService
{
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
            'indicators' => array_fill_keys(['total', 'completed', 'in_progress', 'cancelled', 'not_started', 'efficiency'], null)];
        try {
            $rows = ($this->repository ?? new ProtheusRepository(budgetSeconds: 5))->dashboard($filters);
            foreach ($rows as $row) {
                if ($row['dimension'] === 'total') {
                    $payload['record_count'] = $row['quantity'];
                } else {
                    $payload['groups'][$row['dimension']][] = array_intersect_key($row,
                        array_flip(['code', 'ending', 'branch', 'quantity']));
                }
            }
            if ($payload['record_count'] === null) {
                throw new \RuntimeException('Incomplete aggregate.');
            }
            $payload['available'] = true;
            $payload['queried_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        } catch (Throwable) {
            $payload['record_count'] = null;
            $payload['groups'] = [];
        }

        return $payload;
    }
}
