<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/** Persistence for the NC module; business writes go through NonconformityService. */
final class NonconformityNotificationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('nonconformity_notifications');
        $this->setPrimaryKey('id');
    }
}
