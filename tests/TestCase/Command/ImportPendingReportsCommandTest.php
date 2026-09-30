<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ImportPendingReportsCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public function testLegacySpreadsheetImportCommandsRemainDisabled(): void
    {
        foreach (['import_pending_reports', 'import_report'] as $command) {
            $this->exec($command);
            $this->assertExitError();
            $this->assertErrorContains('Fonte operacional: Protheus.');
        }
    }
}
