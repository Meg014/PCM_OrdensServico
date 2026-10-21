<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Protheus\ProtheusRepository;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final class ActionPlanService
{
    public static function period(array $query): array
    {
        $dates = [];
        foreach (['date_start', 'date_end'] as $key) {
            $value = $query[$key] ?? null;
            if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
                throw new InvalidArgumentException('Informe as datas inicial e final.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01') {
                throw new InvalidArgumentException('Informe datas válidas a partir de 01/01/1900.');
            }
            $dates[$key] = $value;
        }
        if ($dates['date_start'] > $dates['date_end']) {
            throw new InvalidArgumentException('A data final deve ser igual ou posterior à inicial.');
        }

        return $dates;
    }

    public function load(array $query): array
    {
        $period = self::period($query);
        $rows = (new ProtheusRepository())->actionPlan($period['date_start'], $period['date_end']);

        return $this->group($rows);
    }

    /** SQL orders all active entries chronologically; only M entries contribute mechanics. */
    public function group(array $rows): array
    {
        $orders = [];
        $employees = [];
        $names = [];
        foreach ($rows as $row) {
            foreach (['identity_count', 'cost_center_matches', 'equipment_matches', 'service_matches', 'professional_matches'] as $field) {
                if ((int)($row[$field] ?? 0) > 1) {
                    throw new DomainException('Há cadastros ambíguos no TOTVS. Nenhum arquivo foi gerado.');
                }
            }
            $key = json_encode([$row['TJ_FILIAL'], $row['TJ_ORDEM']], JSON_THROW_ON_ERROR);
            if (!isset($orders[$key])) {
                $orders[$key] = [
                    'branch' => (string)$row['TJ_FILIAL'],
                    'number' => (string)$row['TJ_ORDEM'], 'date' => $row['reference_date'],
                    'sector' => trim((string)$row['cost_center_name']),
                    'equipment' => trim((string)$row['equipment_name']),
                    'description' => $this->descriptionText($row['description'] ?? null),
                    'service' => trim((string)$row['service_name']),
                    'status' => $row['TJ_TERMINO'] === 'S' ? 'Finalizada' : 'Aberta',
                    'mechanics' => '',
                ];
            }
            if ($orders[$key]['description'] === '') {
                // Keep the first nonblank entry text; an O.S. description always takes precedence.
                $orders[$key]['description'] = $this->descriptionText($row['entry_description'] ?? null);
            }
            $code = trim((string)($row['TL_CODIGO'] ?? ''));
            $name = trim((string)($row['professional_name'] ?? ''));
            if (($row['TL_TIPOREG'] ?? null) === 'M' && $code !== '' && $name !== '' && !isset($employees[$key][$code])) {
                $employees[$key][$code] = true;
                $names[$key][$name] = $name;
            }
        }
        foreach ($orders as $key => &$order) {
            $order['mechanics'] = implode(' / ', $names[$key] ?? []);
            if ($order['description'] === '') {
                $order['description'] = $this->descriptionText($order['service']);
            }
        }
        unset($order);

        return array_values($orders);
    }

    /** Reject opaque binary/control payloads; keep readable text and its internal whitespace. */
    private function descriptionText(mixed $value): string
    {
        $text = str_replace("\0", '', (string)$value);
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text);
        if ($controls > strlen($text) / 10) {
            return '';
        }
        // Isolated control bytes must not discard an otherwise useful O.S. description.
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/^[\p{Z}\s\x{FEFF}]+|[\p{Z}\s\x{FEFF}]+$/u', '', $text);

        return preg_match('/[\p{L}\p{N}]/u', $text) === 1 ? $text : '';
    }
}
