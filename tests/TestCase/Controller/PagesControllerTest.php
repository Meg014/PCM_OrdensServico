<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\AuthenticatedUserTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class PagesControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AuthenticatedUserTrait;

    public function testSkeletonPagesAreNotPubliclyRouted(): void
    {
        foreach (['/pages/home', '/pages/not_existing'] as $url) {
            $this->get($url);
            $this->assertResponseCode(404, $url);
        }
    }
}
