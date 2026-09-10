<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class UpdateWorkOrderStatusRuleV4 extends BaseMigration
{
    /** Reclassifies STATUS without using any temporal field. */
    public function up(): void
    {
        $this->execute("UPDATE work_order_snapshots SET treated_status = CASE
            WHEN LOWER(TRIM(source_situation)) IN ('cancelada', 'cancelado') THEN 'CANCELADA'
            WHEN LOWER(TRIM(finished_raw)) = 'sim' THEN 'FECHADA'
            ELSE 'EM ABERTO'
        END, status_rule_version = 4");
    }

    public function down(): void
    {
        throw new RuntimeException('A regra de STATUS v4 e uma decisao de negocio irreversivel.');
    }
}
