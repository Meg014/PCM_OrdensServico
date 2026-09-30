<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\AuthenticatedUserTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class PcmControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use AuthenticatedUserTrait;

    public function testRemovedSnapshotRoutesAreNotPubliclyRouted(): void
    {
        foreach (['/pcm/legado', '/pcm/ordens/legado', '/pcm/current-version',
            '/pcm/apresentacao/legado', '/pcm/apresentacao/legado/data', '/pcm/os/1',
            '/pcm/analises', '/pcm/analises/qualidade/missing_service', '/pcm/movimentacao/new'] as $url) {
            $this->get($url);
            $this->assertResponseCode(404, $url);
        }
    }
}
