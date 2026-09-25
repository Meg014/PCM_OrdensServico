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
        foreach (['os', 'filial', 'bem', 'centro', 'area', 'date_start', 'date_end'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new InvalidArgumentException('Filtro inválido.');
            $filters[$key] = trim($value);
        }
        foreach (['date_start', 'date_end'] as $key) {
            if ($filters[$key] === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new InvalidArgumentException('Data inválida.');
        }
        if ($filters['date_start'] !== '' && $filters['date_end'] !== '' && $filters['date_start'] > $filters['date_end']) throw new InvalidArgumentException('Período inválido.');
        $params = ['numero' => $filters['os'], 'filial' => $filters['filial'], 'bem' => $filters['bem'],
            'centro' => $filters['centro'], 'area' => $filters['area'], 'date_start' => $filters['date_start'],
            'date_end' => $filters['date_end']];
        try {
            $data = ($this->repository ?? new ProtheusRepository(budgetSeconds: 15))->generalEntries($params, $page, $limit);
            return ['available' => true, 'filters' => $filters] + $data;
        } catch (Throwable) {
            return ['available' => false, 'filters' => $filters, 'entries' => [], 'has_more' => false];
        }
    }
}
