<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class NameKnownMaintenanceAreas extends BaseMigration
{
    public function up(): void
    {
        $names = [
            'MECANI' => 'Mecânica',
            'ELETRI' => 'Elétrica',
            'CALDEI' => 'Caldeiraria',
            'USINAG' => 'Usinagem',
            'INSTRU' => 'Instrumentação',
            'OPERAC' => 'Operação',
        ];
        foreach ($names as $code => $name) {
            $this->execute(sprintf(
                "UPDATE maintenance_areas SET display_name = '%s' WHERE source_code = '%s'",
                str_replace("'", "''", $name),
                str_replace("'", "''", $code),
            ));
        }
    }

    public function down(): void
    {
        $this->execute('UPDATE maintenance_areas SET display_name = source_code');
    }
}
