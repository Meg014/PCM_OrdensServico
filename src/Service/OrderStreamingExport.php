<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Protheus\EquipmentHistoryService;
use App\Service\Protheus\OrderEntryExportService;
use App\Service\Protheus\OrderListingService;
use App\Service\Protheus\ProtheusSectorService;
use DateTimeImmutable;
use DateTimeZone;

final class OrderStreamingExport
{
    private const FILTER_LABELS = [
        'os' => 'Número da OS', 'filial' => 'Filial', 'bem' => 'Equipamento', 'equipment' => 'Equipamento',
        'centro' => 'Centro de custo', 'cost_center' => 'Centro de custo', 'area' => 'Área/Setor',
        'status' => 'Status', 'service' => 'Serviço', 'service_name' => 'Nome do serviço',
        'maintenance_type' => 'Tipo de manutenção', 'q' => 'Busca textual', 'date_start' => 'Data inicial',
        'date_end' => 'Data final', 'card' => 'Indicador', 'card_status' => 'Status do indicador',
        'backlog_age' => 'Faixa de backlog', 'entry_type' => 'Tipo de apontamento', 'professional' => 'Profissional',
    ];

    public function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    }

    public function orders(string $context, array $query, ?string $area, DateTimeImmutable $generated): array
    {
        $first = match ($context) {
            'sector' => (new ProtheusSectorService())->load((string)$area, $query, true, 1),
            'equipment' => (new EquipmentHistoryService())->load($query, true, 1),
            default => (new OrderListingService())->load($query, true, 1),
        };
        if ($first['not_found'] ?? false) throw new \OutOfBoundsException('Equipamento não encontrado no Protheus.');
        $columns = $this->orderColumns($context);
        $filterText = $this->filters($first['filters']);
        $tableRow = $filterText === '' ? 5 : 6;
        $spec = ['title' => $context === 'equipment' ? 'HISTÓRICO DE MANUTENÇÃO DO EQUIPAMENTO' : 'RELATÓRIO DE ORDENS DE SERVIÇO',
            'scope' => 'Setor/Área: ' . ($first['name'] ?? ($context === 'orders' ? 'Todos os setores' : 'Conforme área de cada OS')),
            'generated' => $generated, 'total_label' => 'Total de OS', 'filter_text' => $filterText,
            'table_row' => $tableRow, 'columns' => $columns, 'sheet_name' => 'Ordens de Serviço'];
        $writer = new StreamingXlsxReport();
        return $writer->write($spec, function (int $page) use ($context, $query, $area, $first): array {
            $data = $page === 1 ? $first : match ($context) {
                'sector' => (new ProtheusSectorService())->load((string)$area, $query, true, $page),
                'equipment' => (new EquipmentHistoryService())->load($query, true, $page),
                default => (new OrderListingService())->load($query, true, $page),
            };
            return ['available' => $data['available'], 'rows' => $data['orders'], 'has_more' => $data['has_more']];
        });
    }

    public function entries(array $query, ?string $area, DateTimeImmutable $generated): array
    {
        $service = new OrderEntryExportService();
        $first = $area === null ? $service->loadGeneral($query, 1) : $service->load($area, $query, 1);
        $filterText = $this->filters($first['filters']);
        $tableRow = $filterText === '' ? 6 : 7;
        $scope = $area === null ? 'Abrangência: Todos os setores' : 'Setor/Área: ' . ($first['name'] ?? $area);
        $spec = ['title' => 'RELATÓRIO DE APONTAMENTOS DE ORDENS DE SERVIÇO', 'scope' => $scope,
            'generated' => $generated, 'total_label' => 'Total de apontamentos', 'distinct_label' => 'OS distintas',
            'filter_text' => $filterText, 'table_row' => $tableRow, 'columns' => $this->entryColumns(),
            'sheet_name' => 'Apontamentos'];
        return (new StreamingXlsxReport())->write($spec, function (int $page) use ($query, $area, $first): array {
            if ($page === 1) {
                $data = $first;
            } else {
                $service = new OrderEntryExportService();
                $data = $area === null ? $service->loadGeneral($query, $page) : $service->load($area, $query, $page);
            }
            return ['available' => $data['available'], 'rows' => $data['entries'], 'has_more' => $data['has_more']];
        });
    }

    public function filename(string $prefix, DateTimeImmutable $generated): string
    {
        $safe = substr(trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $prefix), '_-'), 0, 100);
        return ($safe ?: 'OS') . '_' . $generated->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d_His') . '.xlsx';
    }

    private function filters(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $value) {
            if (!isset(self::FILTER_LABELS[$key]) || !is_scalar($value)) continue;
            $value = trim((string)$value);
            if (in_array(mb_strtolower($value), ['', 'all', 'todos', 'todas'], true)) continue;
            $parts[] = self::FILTER_LABELS[$key] . ': ' . $value;
        }
        return $parts === [] ? '' : 'Filtros: ' . implode(' | ', $parts);
    }

    private function orderColumns(string $context): array
    {
        if ($context === 'orders') {
            $situation = static function (array $row): string {
                $code = rtrim((string)($row['TJ_SITUACA'] ?? ''));
                return match ($code) {
                    'L' => 'LIBERADA',
                    'C' => 'CANCELADA',
                    'P' => 'PENDENTE',
                    default => $code,
                };
            };
            $finished = static fn (array $row): string => match (rtrim((string)($row['TJ_TERMINO'] ?? ''))) {
                'S' => 'SIM',
                'N' => 'NÃO',
                default => '',
            };
            $actualStart = static fn (array $row): array => [$row['TJ_DTMRINI'] ?? '', $row['TJ_HOMRINI'] ?? ''];
            $actualEnd = static fn (array $row): array => [$row['TJ_DTMRFIM'] ?? '', $row['TJ_HOMRFIM'] ?? ''];

            return [
                'TJ_ORDEM' => ['Número da OS', 'text'],
                'descricao' => ['Descrição da OS', 'text', 30],
                'TJ_CODBEM' => ['Código do equipamento', 'text'],
                'equipment_name' => ['Nome do equipamento', 'text'],
                'TJ_SERVICO' => ['Código do serviço', 'text'],
                'service_name' => ['Descrição/Nome do serviço', 'text'],
                'TJ_TIPO' => ['Tipo de manutenção', 'text'],
                'TJ_CODAREA' => ['Área/Setor', 'text'],
                'TJ_CCUSTO' => ['Centro de custo', 'text'],
                'situation_label' => ['Situação', 'text', 18, $situation],
                'finished_label' => ['Finalizada', 'text', 14, $finished],
                'TJ_DTORIGI' => ['Data de origem', 'date', 18],
                'actual_start' => ['Data/Hora de início', 'datetime', 22, $actualStart],
                'actual_end' => ['Data/Hora de fechamento', 'datetime', 22, $actualEnd],
            ];
        }

        $columns = ['TJ_ORDEM' => ['Número da OS', 'text'], 'descricao' => ['Descrição da OS', 'text', 30],
            'TJ_FILIAL' => ['Filial', 'text'], 'TJ_CODBEM' => ['Código do equipamento', 'text'],
            'equipment_name' => ['Nome do equipamento', 'text'], 'TJ_SERVICO' => ['Código do serviço', 'text'],
            'service_name' => ['Nome do serviço', 'text'], 'TJ_TIPO' => ['Tipo de manutenção', 'text'],
            'TJ_CODAREA' => ['Área/Setor', 'text'], 'TJ_CCUSTO' => ['Centro de custo', 'text']];
        if ($context === 'sector') return $columns + ['status' => ['Status', 'text'], 'planned_date' => ['Início previsto (manutenção)', 'date'],
            'TJ_HOMPINI' => ['Hora prevista (manutenção)', 'time'], 'TJ_DTPRINI' => ['Início real (geral)', 'date'], 'TJ_HOPRINI' => ['Hora real (geral)', 'time']];
        $columns += ['TJ_SITUACA' => ['Situação (código TOTVS)', 'text'], 'TJ_TERMINO' => ['Término (indicador TOTVS)', 'text'],
            'reference_date' => ['Data de referência', 'date'], 'TJ_DTORIGI' => ['Data de origem', 'date']];
        foreach (['PP' => 'previsto (geral)', 'PR' => 'real (geral)', 'MP' => 'previsto (manutenção)', 'MR' => 'real (manutenção)'] as $code => $label) {
            foreach (['INI' => 'Início', 'FIM' => 'Término'] as $suffix => $part) {
                $columns['TJ_DT' . $code . $suffix] = [$part . ' ' . $label, 'date'];
                $columns['TJ_HO' . $code . $suffix] = ['Hora: ' . $part . ' ' . $label, 'time'];
            }
        }
        return $columns;
    }

    private function entryColumns(): array
    {
        $type = static fn (array $r): string => match (rtrim((string)($r['TL_TIPOREG'] ?? ''))) {'M' => 'Mão de obra', 'P' => 'Material', default => rtrim((string)($r['TL_TIPOREG'] ?? ''))};
        return ['TJ_ORDEM' => ['Número da OS', 'text'], 'TJ_FILIAL' => ['Filial', 'text'], 'TJ_CODBEM' => ['Código do equipamento', 'text'],
            'equipment_name' => ['Nome do equipamento', 'text'], 'descricao' => ['Descrição da OS', 'text', 30],
            'TJ_SERVICO' => ['Código do serviço', 'text'], 'service_name' => ['Nome do serviço', 'text'],
            'TJ_CODAREA' => ['Área/Setor', 'text'], 'TJ_CCUSTO' => ['Centro de custo', 'text'],
            'TJ_TIPO' => ['Tipo de manutenção', 'text'], 'status' => ['Status da OS', 'text'],
            'entry_type' => ['Tipo do apontamento', 'text', 24, $type], 'TL_CODIGO' => ['Código do apontamento', 'text'],
            'professional_code' => ['Código do responsável/profissional', 'text', 24, static fn ($r) => rtrim((string)$r['TL_TIPOREG']) === 'M' ? $r['TL_CODIGO'] : ''],
            'professional_name' => ['Nome do responsável/profissional', 'text'],
            'product_code' => ['Código do material/produto', 'text', 24, static fn ($r) => rtrim((string)$r['TL_TIPOREG']) === 'P' ? $r['TL_CODIGO'] : ''],
            'product_name' => ['Descrição do material/produto', 'text'], 'TL_DTINICI' => ['Data inicial', 'date'],
            'TL_DTFIM' => ['Data final', 'date'], 'TL_HOINICI' => ['Hora inicial', 'time'], 'TL_HOFIM' => ['Hora final', 'time'],
            'TL_QUANTID' => ['Quantidade registrada', 'text'], 'TL_UNIDADE' => ['Unidade', 'text']];
    }
}
