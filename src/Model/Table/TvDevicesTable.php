<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class TvDevicesTable extends Table
{
    /** Associates each revocable credential with its account. */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->belongsTo('Users');
    }
}
