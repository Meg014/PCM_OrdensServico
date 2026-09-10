<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\MaintenanceAreasTable;

final class PcmPresentationService
{
    /** Creates the lightweight presentation query service. */
    public function __construct(private readonly CurrentSnapshotService $current = new CurrentSnapshotService())
    {
    }

    /**
     * @return array{import_id:?int,report_date:?string,updated_at:?string,screens:list<array<string,int|string>>}
     */
    public function payload(): array
    {
        $import = $this->current->currentImport();
        if ($import === null) {
            return ['import_id' => null, 'report_date' => null, 'updated_at' => null, 'screens' => [
                $this->emptyScreen('general', 'PCM - VISÃO GERAL'),
            ]];
        }

        $indicators = new PcmIndicatorService($this->current);
        $screens = [$this->screen('general', 'PCM - VISÃO GERAL', $indicators->calculate())];
        foreach ($this->orderedPresentationAreas($this->current->areas()) as $presentationArea) {
            $area = $presentationArea['area'];
            $screens[] = $this->screen(
                (string)$area->source_code,
                'PCM - ' . $presentationArea['label'],
                $indicators->calculate((int)$area->id),
            );
        }

        return [
            'import_id' => (int)$import->id,
            'report_date' => $import->report_date->format('Y-m-d'),
            'updated_at' => $import->finished_at->format(DATE_ATOM),
            'screens' => $screens,
        ];
    }

    /** @return list<array{area:object,label:string}> */
    private function orderedPresentationAreas(array $areas): array
    {
        $areasByCode = [];
        foreach ($areas as $area) {
            $areasByCode[strtoupper((string)$area->source_code)] = $area;
        }
        $ordered = [];
        foreach (MaintenanceAreasTable::FRIENDLY_NAMES as $code => $friendlyName) {
            if (isset($areasByCode[$code])) {
                $area = $areasByCode[$code];
                $label = mb_strtoupper($friendlyName);
                $ordered[] = compact('area', 'label');
                unset($areasByCode[$code]);
            }
        }

        foreach ($areasByCode as $area) {
            $ordered[] = ['area' => $area, 'label' => mb_strtoupper((string)$area->display_name)];
        }

        return $ordered;
    }

    /** @return array{open:int,completed:int,preventive:int,corrective:int,improvement:int,blank_maintenance_type:int} */
    private function emptyCounts(): array
    {
        return ['open' => 0, 'completed' => 0, 'preventive' => 0, 'corrective' => 0,
            'improvement' => 0, 'blank_maintenance_type' => 0, 'emergency' => 0, 'scheduled' => 0, 'offseason' => 0];
    }

    /** @return array<string, int|string> */
    private function emptyScreen(string $key, string $title): array
    {
        return compact('key', 'title') + $this->emptyCounts();
    }

    /** Presents the same backend counters as the dashboards without audit counters. */
    private function screen(string $key, string $title, array $counts): array
    {
        return compact('key', 'title') + array_intersect_key($counts, $this->emptyCounts());
    }
}
