<?php
declare(strict_types=1);
namespace App\Model\Table;

use Cake\ORM\Table;

final class NonconformityResponsiblesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('nonconformity_responsibles');
        $this->setPrimaryKey('id');
    }
}
