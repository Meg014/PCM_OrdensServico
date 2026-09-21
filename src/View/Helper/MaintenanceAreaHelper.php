<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Model\Table\MaintenanceAreasTable;
use Cake\Datasource\FactoryLocator;
use Cake\View\Helper;

class MaintenanceAreaHelper extends Helper
{
    private ?array $names = null;

    /** Resolves display names once per view, retaining the original source code. */
    public function name(?string $code): string
    {
        if ($this->names === null) {
            $this->names = FactoryLocator::get('Table')->get('MaintenanceAreas')->find()->all()
                ->combine('source_code', 'display_name')->toArray();
        }

        return $this->names[$code] ?? MaintenanceAreasTable::FRIENDLY_NAMES[$code] ?? ($code ?: '—');
    }
}
