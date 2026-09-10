<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class RenameSnapshotSourceRowNumber extends BaseMigration
{
    /** Renames the source position without changing its definition or stored values. */
    public function change(): void
    {
        $this->table('work_order_snapshots')
            ->renameColumn('row_number', 'source_row_number')
            ->update();
    }
}
