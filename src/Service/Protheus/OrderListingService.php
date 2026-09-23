<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;
use Throwable;

/** Web boundary: validates input before querying and never exposes driver exceptions. */
final class OrderListingService
{
    public function __construct(private ?ProtheusRepository $repository = null)
    {
    }

    public function load(array $query): array
    {
        $filters = [];
        foreach (['os', 'filial', 'bem', 'centro', 'date_start', 'date_end'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100) {
                throw new InvalidArgumentException('Filtros inválidos.');
            }
            $filters[$key] = trim($value);
        }
        foreach (['date_start', 'date_end'] as $key) {
            if ($filters[$key] !== '') {
                if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $filters[$key])) {
                    throw new InvalidArgumentException('Data inválida.');
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key]) {
                    throw new InvalidArgumentException('Data inválida.');
                }
            }
        }
        if ($filters['date_start'] !== '' && $filters['date_end'] !== '' && $filters['date_start'] > $filters['date_end']) {
            throw new InvalidArgumentException('Período inválido.');
        }
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $limit = filter_var($query['limite'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false) {
            throw new InvalidArgumentException('Paginação inválida.');
        }
        try {
            $repository = $this->repository ?? new ProtheusRepository(budgetSeconds: 5);
            $result = $repository->findOrders(
                $filters['os'] === '' ? null : $filters['os'],
                $filters['filial'] === '' ? null : $filters['filial'],
                $filters['bem'] === '' ? null : $filters['bem'], $page, $limit,
                $filters['centro'], $filters['date_start'], $filters['date_end'],
            );

            return $result + ['filters' => $filters, 'available' => true];
        } catch (Throwable) {
            return ['orders' => [], 'page' => $page, 'limit' => $limit, 'has_more' => false,
                'filters' => $filters, 'available' => false];
        }
    }
}
