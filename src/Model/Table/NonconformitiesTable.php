<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/** Persistence for the NC module; business writes go through NonconformityService. */
final class NonconformitiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('nonconformities');
        $this->setPrimaryKey('id');
    }
}
