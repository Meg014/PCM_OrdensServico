<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class ServicesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('services');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->hasMany('WorkOrderSnapshots');
    }
}
