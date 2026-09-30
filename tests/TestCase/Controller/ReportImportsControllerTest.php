<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\AuthenticatedUserTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ReportImportsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AuthenticatedUserTrait;

    public function testSpreadsheetImportPagesAreNotPubliclyRouted(): void
    {
        foreach (['/importacoes', '/importacoes/manual'] as $url) {
            $this->get($url);
            $this->assertResponseCode(404, $url);
        }
    }
}
