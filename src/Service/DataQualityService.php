<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\FactoryLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use DateTimeInterface;
use InvalidArgumentException;

final class DataQualityService
{
    /** @var array<string, array{label:string,shortLabel:string,level:string}> */
    public const RULES = [
        'missing_area' => ['label' => 'OS sem área de manutenção', 'shortLabel' => 'Sem área', 'level' => 'ATENÇÃO'],
        'missing_service' => ['label' => 'OS sem nome de serviço', 'shortLabel' => 'Sem serviço', 'level' => 'ATENÇÃO'],
        'missing_cost_center' => ['label' => 'OS sem centro de custo', 'shortLabel' => 'Sem centro de custo', 'level' => 'ATENÇÃO'],
        'completed_without_start' => ['label' => 'OS fechada sem início real válido', 'shortLabel' => 'Fechada sem início', 'level' => 'ATENÇÃO'],
        'start_before_origin' => ['label' => 'Início real anterior à Data Origin.', 'shortLabel' => 'Início anterior à origem', 'level' => 'ATENÇÃO'],
        'possible_date_inconsistency' => ['label' => 'Possível inconsistência de data', 'shortLabel' => 'Possíveis inconsist. de data', 'level' => 'ATENÇÃO'],
    ];

    private Table $snapshots;

    public function __construct(private readonly CurrentSnapshotService $currentSnapshot = new CurrentSnapshotService())
    {
        $this->snapshots = FactoryLocator::get('Table')->get('WorkOrderSnapshots');
    }

    /**
     * @return array{reportDate:?DateTimeInterface,total:int,indicators:array<string,array<string,mixed>>}
     */
    public function summary(): array
    {
        $import = $this->currentSnapshot->currentImport();
        if ($import === null) {
            return ['reportDate' => null, 'total' => 0, 'indicators' => $this->emptyIndicators()];
        }
        $total = $this->baseQuery((int)$import->id)->count();
        $indicators = [];
        foreach (self::RULES as $type => $definition) {
            $count = $this->applyRule($this->baseQuery((int)$import->id), $type)->count();
            $indicators[$type] = $definition + [
                'type' => $type,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0.0,
            ];
        }

        return ['reportDate' => $import->report_date, 'total' => $total, 'indicators' => $indicators];
    }

    public function detailQuery(string $type): ?SelectQuery
    {
        $this->definition($type);
        $import = $this->currentSnapshot->currentImport();
        if ($import === null) {
            return null;
        }

        return $this->applyRule($this->baseQuery((int)$import->id), $type);
    }

    /** @return array{label:string,shortLabel:string,level:string} */
    public function definition(string $type): array
    {
        if (!isset(self::RULES[$type])) {
            throw new InvalidArgumentException('Tipo de inconsistência desconhecido.');
        }

        return self::RULES[$type];
    }

    public function valueFor(object $snapshot, string $type): string
    {
        $this->definition($type);

        return match ($type) {
            'missing_area' => 'Área de manutenção não informada',
            'missing_service' => 'Nome do serviço não informado',
            'missing_cost_center' => 'Centro de custo não informado',
            'completed_without_start' => 'STATUS: FECHADA; R. In. Man.: vazio',
            'start_before_origin' => sprintf(
                'R. In. Man.: %s; Data Origin.: %s',
                $this->formatDate($snapshot->maintenance_actual_start),
                $this->formatDate($snapshot->origin_date, false),
            ),
            'possible_date_inconsistency' => $this->dateInconsistencyValue($snapshot),
        };
    }

    private function baseQuery(int $importId): SelectQuery
    {
        return $this->snapshots->find()->where(['WorkOrderSnapshots.report_import_id' => $importId]);
    }

    private function applyRule(SelectQuery $query, string $type): SelectQuery
    {
        $this->definition($type);
        $field = static fn(string $name): string => "WorkOrderSnapshots.{$name}";

        return match ($type) {
            'missing_area' => $query->where(['OR' => [$field('maintenance_area_code') . ' IS' => null, $field('maintenance_area_code') => '']]),
            'missing_service' => $query->where(['OR' => [$field('service_name') . ' IS' => null, $field('service_name') => '']]),
            'missing_cost_center' => $query->where(['OR' => [$field('cost_center_code') . ' IS' => null, $field('cost_center_code') => '']]),
            'completed_without_start' => $query->where([
                $field('treated_status') => WorkOrderStatusResolver::COMPLETED,
                $field('maintenance_actual_start') . ' IS' => null,
            ]),
            'start_before_origin' => $query->where($field('maintenance_actual_start') . ' < ' . $field('origin_date')),
            'possible_date_inconsistency' => $query->where(['OR' => [
                $field('maintenance_actual_start') . ' < ' . $field('origin_date'),
                ['AND' => [$field('maintenance_actual_start') . ' IS' => null, $field('maintenance_actual_end') . ' IS NOT' => null]],
                $field('maintenance_actual_end') . ' < ' . $field('maintenance_actual_start'),
            ]]),
        };
    }

    /** @return array<string,array<string,mixed>> */
    private function emptyIndicators(): array
    {
        $result = [];
        foreach (self::RULES as $type => $definition) {
            $result[$type] = $definition + ['type' => $type, 'count' => 0, 'percentage' => 0.0];
        }

        return $result;
    }

    private function dateInconsistencyValue(object $snapshot): string
    {
        if ($snapshot->maintenance_actual_start === null && $snapshot->maintenance_actual_end !== null) {
            return 'R. Fim Man. preenchido sem R. In. Man.';
        }
        if ($snapshot->maintenance_actual_start !== null && $snapshot->origin_date !== null
            && $snapshot->maintenance_actual_start < $snapshot->origin_date) {
            return sprintf(
                'R. In. Man.: %s; Data Origin.: %s',
                $this->formatDate($snapshot->maintenance_actual_start),
                $this->formatDate($snapshot->origin_date, false),
            );
        }

        return sprintf(
            'R. Fim Man.: %s anterior a R. In. Man.: %s',
            $this->formatDate($snapshot->maintenance_actual_end),
            $this->formatDate($snapshot->maintenance_actual_start),
        );
    }

    private function formatDate(mixed $value, bool $withTime = true): string
    {
        return $value instanceof DateTimeInterface ? $value->format($withTime ? 'd/m/Y H:i' : 'd/m/Y') : 'vazio';
    }
}
