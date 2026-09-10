<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class UpdateWorkOrderStatusRuleV3 extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE work_order_snapshots SET treated_status = CASE
            WHEN LOWER(TRIM(source_situation)) IN ('cancelada', 'cancelado') THEN 'CANCELADA'
            WHEN LOWER(TRIM(finished_raw)) = 'sim' THEN 'FECHADA'
            WHEN maintenance_actual_start IS NOT NULL THEN 'EM ANDAMENTO'
            ELSE 'EM ABERTO'
        END, status_rule_version = 3");
    }

    public function down(): void
    {
        throw new RuntimeException('A regra de STATUS v3 e uma decisao de negocio irreversivel.');
    }
}
