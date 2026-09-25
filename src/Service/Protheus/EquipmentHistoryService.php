<?php
declare(strict_types=1);
namespace App\Service\Protheus;

use App\Service\PcmTimeFormatter;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class EquipmentHistoryService
{
    public function __construct(private readonly ?ProtheusRepository $repository = null)
    {
    }

    public function load(array $query, bool $export = false): array
    {
        if ($export) $query = array_replace($query, ['page' => 1, 'limit' => 20, 'limite' => 20]);
        $values = [];
        foreach (['bem', 'filial', 'date_start', 'date_end', 'type', 'status'] as $key) {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException('Parâmetro inválido.');
            }
            $values[$key] = rtrim($value);
        }
        if ($values['bem'] === '' || !array_key_exists('filial', $query)
            || !in_array($values['status'], ['', 'open', 'closed'], true)
            || !in_array($values['type'], ['', 'COR', 'PRE', 'MEL'], true)) {
            throw new InvalidArgumentException('Informe bem, filial e filtros válidos.');
        }
        foreach (['date_start', 'date_end'] as $key) {
            if ($values[$key] === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values[$key]);
            if (!$date || $date->format('Y-m-d') !== $values[$key]) throw new InvalidArgumentException('Data inválida.');
        }
        if ($values['date_start'] !== '' && $values['date_end'] !== '' && $values['date_start'] > $values['date_end']) {
            throw new InvalidArgumentException('Período inválido.');
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false) throw new InvalidArgumentException('Paginação inválida.');
        if ($export) $limit = \App\Service\OrderExcelReport::MAX_ROWS;
        $result = ['available' => false, 'not_found' => false, 'filters' => $values, 'page' => $page, 'limit' => $limit];
        $formatter = new PcmTimeFormatter();
        $today = new DateTimeImmutable($formatter->format(new DateTimeImmutable(), 'Y-m-d'));
        $windows = [];
        foreach ([30, 90, 365] as $days) {
            $windows['since' . $days] = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
            $windows['until' . $days] = $today->format('Y-m-d');
        }
        try {
            $data = ($this->repository ?? new ProtheusRepository(budgetSeconds: 10))->equipmentPortfolio(
                $values['bem'], $values['filial'], array_diff_key($values, array_flip(['bem', 'filial'])), $windows, $page, $limit, $export,
            );
            return array_replace($result, $data, ['available' => true,
                'not_found' => $data['header'] === null && (int)$data['summary']['all_count'] === 0,
                'queried_at' => $formatter->format(new DateTimeImmutable(), 'd/m/Y, H:i:s')]);
        } catch (Throwable) {
            return $result;
        }
    }
}
