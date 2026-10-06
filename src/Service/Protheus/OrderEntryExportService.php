<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use App\Model\Table\MaintenanceAreasTable;
use App\Model\Table\WorkOrderSnapshotsTable;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** Validates sector filters and requests one bounded batch of STL010 entries. */
final class OrderEntryExportService
{
    public function __construct(private readonly ?ProtheusRepository $repository = null)
    {
    }

    public function load(string $area, array $query, int $page = 1, int $limit = \App\Service\StreamingXlsxReport::BATCH_SIZE): array
    {
        if (!preg_match('/^[A-Z0-9_-]{1,30}$/D', $area)) throw new InvalidArgumentException('Área inválida.');
        $filters = [];
        foreach ([...ProtheusSectorService::FILTERS, 'entry_type', 'professional'] as $key) {
            $value = $query[$key] ?? '';
            $max = $key === 'service_name' ? 255 : 100;
            if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Filtro inválido.');
            }
            $filters[$key] = trim($value);
        }
        if (!in_array($filters['status'], ['', 'EM ABERTO', 'FECHADA'], true)
            || !in_array($filters['entry_type'], ['', 'M', 'P'], true)
            || !in_array($filters['backlog_age'], ['', 'all', ...array_keys(ProtheusSectorService::BACKLOG_AGES)], true)
            || !in_array($filters['card'], ['', 'all', 'safra', 'offseason', ...array_keys(ProtheusSectorService::CATEGORIES)], true)
            || !in_array($filters['card_status'], ['', 'EM ABERTO', 'FECHADA'], true)) {
            throw new InvalidArgumentException('Filtro inválido.');
        }
        foreach (['date_start', 'date_end'] as $key) {
            if ($filters[$key] === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new InvalidArgumentException('Data inválida.');
        }
        if ($filters['date_start'] !== '' && $filters['date_end'] !== '' && $filters['date_start'] > $filters['date_end']) {
            throw new InvalidArgumentException('Período inválido.');
        }
        $params = array_diff_key($filters, array_flip([
            'date_start', 'date_end', 'card', 'card_status', 'backlog_age', 'entry_type', 'professional',
        ]));
        $params += ['area' => $area, 'cutoff' => str_replace('-', '', WorkOrderSnapshotsTable::OPERATIONAL_START)];
        if ($params['q'] !== '') $params['q'] = '%' . strtr($params['q'], ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~[']) . '%';
        $selection = [
            'backlog_age' => $filters['backlog_age'], 'as_of' => (new DateTimeImmutable())->format('Y-m-d'),
            'status' => $filters['card_status'], 'type' => ProtheusSectorService::TYPES[$filters['card']] ?? '',
            'services' => ProtheusSectorService::SERVICES[$filters['card']] ?? [],
            'season' => in_array($filters['card'], ['safra', 'offseason'], true) ? $filters['card'] : '',
            'entry_type' => $filters['entry_type'], 'professional' => $filters['professional'],
        ];
        $result = ['available' => false, 'has_more' => false, 'entries' => [], 'filters' => $filters,
            'code' => $area, 'name' => MaintenanceAreasTable::FRIENDLY_NAMES[$area] ?? $area];
        try {
            $data = ($this->repository ?? new ProtheusRepository(budgetSeconds: 15))->sectorEntries(
                $params, $filters['date_start'], $filters['date_end'], $selection, $page, $limit,
            );
            return array_replace($result, $data, ['available' => true]);
        } catch (Throwable) {
            return $result;
        }
    }

    public function loadGeneral(array $query, int $page = 1, int $limit = \App\Service\StreamingXlsxReport::BATCH_SIZE): array
    {
        $filters = [];
        foreach (['os', 'filial', 'bem', 'nome_bem', 'centro', 'centro_modo', 'area', 'servico', 'tipo',
            'situacao', 'termino', 'date_start', 'date_end', 'historico', 'safra', 'analitico', 'unidade',
            'card', 'card_status', 'backlog_age', 'q', 'status', 'service_name', 'entry_type', 'professional'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new InvalidArgumentException('Filtro inválido.');
            $filters[$key] = trim($value);
        }
        if (!in_array($filters['centro_modo'], ['', 'exact', 'blank', 'null'], true)
            || ($filters['centro_modo'] === 'exact' && $filters['centro'] === '')) {
            throw new InvalidArgumentException('Filtro de centro de custo inválido.');
        }
        if ($filters['centro'] !== '' && $filters['centro_modo'] === '') $filters['centro_modo'] = 'exact';
        if (!in_array($filters['historico'], ['', '1'], true)) {
            throw new InvalidArgumentException('Escopo histÃ³rico invÃ¡lido.');
        }
        if (!in_array($filters['safra'], ['', '1'], true)) throw new InvalidArgumentException('Escopo de Safra invÃ¡lido.');
        if (!in_array($filters['analitico'], ['', '1'], true)) {
            throw new InvalidArgumentException('Escopo analítico inválido.');
        }
        $filters['unidade'] = ProtheusUnit::validate($filters['unidade']);
        if ($filters['historico'] === '1' && $filters['unidade'] === ProtheusUnit::OTHER) {
            throw new InvalidArgumentException('A visão histórica não inclui Outros / Sem unidade.');
        }
        foreach (['date_start', 'date_end'] as $key) {
            if ($filters[$key] === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new InvalidArgumentException('Data inválida.');
        }
        if ($filters['date_start'] !== '' && $filters['date_end'] !== '' && $filters['date_start'] > $filters['date_end']) throw new InvalidArgumentException('Período inválido.');
        if ($filters['card'] !== '' || $filters['backlog_age'] !== '') {
            if ($filters['area'] === '') throw new InvalidArgumentException('Setor do drill-down inválido.');
            return $this->load($filters['area'], [
                'filial' => $filters['filial'], 'equipment' => $filters['bem'], 'service' => $filters['servico'],
                'service_name' => $filters['service_name'], 'cost_center' => $filters['centro'],
                'maintenance_type' => $filters['tipo'], 'q' => $filters['q'], 'status' => $filters['status'],
                'date_start' => $filters['date_start'], 'date_end' => $filters['date_end'],
                'card' => $filters['card'], 'card_status' => $filters['card_status'],
                'backlog_age' => $filters['backlog_age'], 'unit' => $filters['unidade'],
                'entry_type' => $filters['entry_type'], 'professional' => $filters['professional'],
            ], $page, $limit);
        }
        $params = ['numero' => $filters['os'], 'filial' => $filters['filial'], 'bem' => $filters['bem'],
            'nome_bem' => OrderListingService::equipmentNamePattern($filters['nome_bem']),
            'centro' => $filters['centro'], 'centro_modo' => $filters['centro_modo'], 'area' => $filters['area'],
            'servico' => $filters['servico'], 'tipo' => $filters['tipo'], 'situacao' => $filters['situacao'],
            'termino' => $filters['termino'], 'date_start' => $filters['date_start'], 'date_end' => $filters['date_end'],
            'historico' => $filters['historico'], 'safra' => $filters['safra'],
            'analitico' => $filters['analitico'], 'unidade' => $filters['unidade']];
        try {
            $data = ($this->repository ?? new ProtheusRepository(budgetSeconds: 15))->generalEntries($params, $page, $limit);
            return ['available' => true, 'filters' => $filters] + $data;
        } catch (Throwable) {
            return ['available' => false, 'filters' => $filters, 'entries' => [], 'has_more' => false];
        }
    }
}
