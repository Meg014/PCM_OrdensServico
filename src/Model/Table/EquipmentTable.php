<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class EquipmentTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('equipment');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->hasMany('WorkOrders');
        $this->hasMany('WorkOrderSnapshots');
    }
}
