<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class CostCentersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('cost_centers');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->hasMany('WorkOrderSnapshots');
    }
}
