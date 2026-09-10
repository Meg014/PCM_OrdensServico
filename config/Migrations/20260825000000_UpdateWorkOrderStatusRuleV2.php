<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class UpdateWorkOrderStatusRuleV2 extends BaseMigration
{
    /** Reclassifies only derived STATUS fields; imported source values remain untouched. */
    public function up(): void
    {
        $this->execute("UPDATE work_order_snapshots SET treated_status = CASE
            WHEN LOWER(TRIM(source_situation)) IN ('cancelada', 'cancelado') THEN 'CANCELADA'
            WHEN LOWER(TRIM(finished_raw)) = 'sim' THEN 'CONCLUÍDA'
            ELSE 'EM ANDAMENTO'
        END, status_rule_version = 2");
    }

    /** The superseded business rule must not be restored. */
    public function down(): void
    {
        throw new RuntimeException('A regra de STATUS v2 é uma decisão de negócio irreversível.');
    }
}
