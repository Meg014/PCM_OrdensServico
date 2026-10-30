<?php
declare(strict_types=1);
use Migrations\BaseMigration;

final class ExpandNonconformityMeasure extends BaseMigration
{
    public function up(): void
    {
        $this->table('nonconformity_actions')->changeColumn('measure', 'text', ['null' => false])->update();
    }
    public function down(): void
    {
        // A narrowing rollback would truncate free-text measures; retain the wider storage.
        throw new RuntimeException('Reversão automática bloqueada para preservar o conteúdo de Medida.');
    }
}
