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
            'situacao', 'termino', 'date_start', 'date_end', 'historico', 'safra', 'analitico', 'unidade',
            'card', 'card_status', 'backlog_age', 'q', 'status', 'service_name'] as $key) {
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
        if (!in_array($filters['historico'], ['', '1'], true)) {
            throw new InvalidArgumentException('Escopo histÃ³rico invÃ¡lido.');
        }
        if (!in_array($filters['safra'], ['', '1'], true)) throw new InvalidArgumentException('Escopo de Safra invÃ¡lido.');
        if (!in_array($filters['analitico'], ['', '1'], true)) {
            throw new InvalidArgumentException('Escopo analítico inválido.');
        }
        $filters['unidade'] = ProtheusUnit::validate($filters['unidade']);
        if (!in_array($filters['card'], ['', 'safra', 'offseason'], true)
            || !in_array($filters['card_status'], ['', 'EM ABERTO', 'FECHADA'], true)
            || !in_array($filters['backlog_age'], ['', 'all', ...array_keys(ProtheusSectorService::BACKLOG_AGES)], true)) {
            throw new InvalidArgumentException('Drill-down invÃ¡lido.');
        }
        $drilldown = $filters['card'] !== '' || $filters['backlog_age'] !== '';
        if ($drilldown && $filters['area'] === '') throw new InvalidArgumentException('Setor do drill-down invÃ¡lido.');
        if (!$drilldown && ($filters['q'] !== '' || $filters['status'] !== '' || $filters['service_name'] !== '')) {
            throw new InvalidArgumentException('Filtro exclusivo de drill-down.');
        }
        if ($filters['historico'] === '1' && $filters['unidade'] === ProtheusUnit::OTHER) {
            throw new InvalidArgumentException('A visão histórica não inclui Outros / Sem unidade.');
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
            if ($drilldown) {
                $sector = (new ProtheusSectorService($this->repository))->load($filters['area'], [
                    'filial' => $filters['filial'], 'equipment' => $filters['bem'],
                    'service' => $filters['servico'], 'service_name' => $filters['service_name'],
                    'cost_center' => $filters['centro'], 'maintenance_type' => $filters['tipo'],
                    'q' => $filters['q'], 'status' => $filters['status'],
                    'date_start' => $filters['date_start'], 'date_end' => $filters['date_end'],
                    'card' => $filters['card'], 'card_status' => $filters['card_status'],
                    'backlog_age' => $filters['backlog_age'], 'unit' => $filters['unidade'],
                    'page' => (string)$page, 'limit' => (string)$limit,
                ], $export, $exportPage);
                if (!$sector['available']) throw new \RuntimeException('Drill-down indisponÃ­vel.');
                if ($filters['backlog_age'] !== '') {
                    $age = $filters['backlog_age'];
                    $total = $age === 'all' ? $sector['backlog']['total'] : $sector['backlog']['ages'][$age];
                    $label = 'Backlog · ' . ($age === 'all' ? 'Total de O.S. em aberto' : ProtheusSectorService::BACKLOG_AGES[$age]);
                } else {
                    $state = $filters['card_status'] === 'EM ABERTO' ? 'open' : 'completed';
                    $total = $sector['cards'][$filters['card'] . '_' . $state];
                    $label = ($filters['card'] === 'safra' ? 'Safra' : 'Entressafra') . ' · '
                        . ($state === 'open' ? 'Em aberto' : 'Fechadas');
                }
                return ['orders' => $sector['orders'], 'page' => $sector['page'], 'limit' => $sector['limit'],
                    'has_more' => $sector['has_more'], 'filters' => $filters, 'available' => true,
                    'drilldown' => ['label' => $label, 'total' => $total]];
            }
            $repository = $this->repository ?? new ProtheusRepository(budgetSeconds: 5, areaScope: $this->areaScope);
            $result = $repository->findOrders(
                $filters['os'] === '' ? null : $filters['os'],
                $filters['filial'] === '' ? null : $filters['filial'],
                $filters['bem'] === '' ? null : $filters['bem'], $page, $limit,
                $filters['centro'], $filters['date_start'], $filters['date_end'], $export, $filters['area'],
                $filters['centro_modo'], $filters['servico'], $filters['tipo'], $filters['situacao'], $filters['termino'],
                $filters['historico'], $filters['unidade'], self::equipmentNamePattern($filters['nome_bem']),
                $filters['analitico'], $filters['safra'],
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
