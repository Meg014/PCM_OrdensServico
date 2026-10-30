<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/** Persistence for the NC module; business writes go through NonconformityService. */
final class NonconformityManagerAssignmentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('nonconformity_manager_assignments');
        $this->setPrimaryKey('id');
    }
}
