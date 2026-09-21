<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class MaintenanceArea extends Entity
{
    protected array $_accessible = ['*' => true, 'id' => false];

    /** Falls back to friendly labels without changing stored names or codes. */
    protected function _getDisplayName(?string $value): string
    {
        $code = (string)($this->_fields['source_code'] ?? '');
        if (trim((string)$value) !== '' && strtoupper(trim($value)) !== strtoupper($code)) {
            return $value;
        }

        return \App\Model\Table\MaintenanceAreasTable::FRIENDLY_NAMES[strtoupper($code)] ?? ($code ?: 'Sem setor');
    }
}
