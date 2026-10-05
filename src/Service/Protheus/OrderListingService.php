<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use InvalidArgumentException;
use Throwable;

/** Web boundary: validates input before querying and never exposes driver exceptions. */
final class OrderListingService
{
    public function __construct(private ?ProtheusRepository $repository = null, private readonly ?string $areaScope = null)
    {
    }

    public function load(array $query, bool $export = false, ?int $exportPage = null): array
    {
        if ($export) $query = array_replace($query, ['page' => $exportPage ?? 1, 'limite' => \App\Service\StreamingXlsxReport::BATCH_SIZE]);
        $filters = [];
        foreach (['os', 'filial', 'bem', 'nome_bem', 'centro', 'centro_modo', 'area', 'servico', 'tipo',
            'situacao', 'termino', 'date_start', 'date_end', 'historico', 'unidade'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100) {
                throw new InvalidArgumentException('Filtros inválidos.');
            }
            $filters[$key] = trim($value);
        }
        if (!in_array($filters['centro_modo'], ['', 'exact', 'blank', 'null'], true)
            || ($filters['centro_modo'] === 'exact' && $filters['centro'] === '')) {
            throw new InvalidArgumentException('Filtro de centro de custo inválido.');
        }
        if ($filters['centro'] !== '' && $filters['centro_modo'] === '') {
            $filters['centro_modo'] = 'exact';
        }
        if (!in_array($filters['historico'], ['', '1'], true)
            || !in_array($filters['unidade'], ['', 'factory', 'mill'], true)
            || ($filters['unidade'] !== '' && $filters['historico'] !== '1')) {
            throw new InvalidArgumentException('Escopo histÃ³rico invÃ¡lido.');
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
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($query['limite'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,
            'max_range' => $export ? \App\Service\StreamingXlsxReport::BATCH_SIZE : 100]]);
        if ($page === false || $limit === false || $page > intdiv(PHP_INT_MAX, (int)$limit)) {
            throw new InvalidArgumentException('Paginação inválida.');
        }
        try {
            $repository = $this->repository ?? new ProtheusRepository(budgetSeconds: 5, areaScope: $this->areaScope);
            $result = $repository->findOrders(
                $filters['os'] === '' ? null : $filters['os'],
                $filters['filial'] === '' ? null : $filters['filial'],
                $filters['bem'] === '' ? null : $filters['bem'], $page, $limit,
                $filters['centro'], $filters['date_start'], $filters['date_end'], $export, $filters['area'],
                $filters['centro_modo'], $filters['servico'], $filters['tipo'], $filters['situacao'], $filters['termino'],
                $filters['historico'], $filters['unidade'], self::equipmentNamePattern($filters['nome_bem']),
            );

            return $result + ['filters' => $filters, 'available' => true];
        } catch (Throwable) {
            return ['orders' => [], 'page' => $page, 'limit' => $limit, 'has_more' => false,
                'filters' => $filters, 'available' => false];
        }
    }

    /** Literal case-insensitive substring pattern for SQL Server LIKE. */
    public static function equipmentNamePattern(string $name): string
    {
        if ($name === '') {
            return '';
        }

        return '%' . str_replace(['~', '%', '_'], ['~~', '~%', '~_'], mb_strtoupper($name)) . '%';
    }

    public function areas(): array
    {
        try {
            return array_values(array_filter(array_map(static fn (array $row): string => trim((string)($row['code'] ?? '')),
                ($this->repository ?? new ProtheusRepository(budgetSeconds: 5, areaScope: $this->areaScope))->findAreas())));
        } catch (Throwable) {
            return [];
        }
    }
}
