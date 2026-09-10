<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;

class MaintenanceAreasTable extends Table
{
    /** Canonical user-facing names for known TOTVS maintenance area codes. */
    public const FRIENDLY_NAMES = [
        'ELETRI' => 'Elétrica',
        'MECANI' => 'Mecânica',
        'CALDEI' => 'Caldeiraria',
        'USINAG' => 'Usinagem',
        'INSTRU' => 'Instrumentação',
        'OPERAC' => 'Operação',
    ];

    /** Configures the maintenance areas repository. */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('maintenance_areas');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->hasMany('WorkOrderSnapshots');
    }

    /**
     * Lists only areas represented in the selected snapshot.
     */
    public function findForImport(SelectQuery $query, int $reportImportId): SelectQuery
    {
        return $query
            ->innerJoinWith(
                'WorkOrderSnapshots',
                fn(SelectQuery $snapshots): SelectQuery => $snapshots
                    ->where(['WorkOrderSnapshots.report_import_id' => $reportImportId]),
            )
            ->distinct([$this->aliasField('id')])
            ->orderByAsc($this->aliasField('display_name'));
    }
}
