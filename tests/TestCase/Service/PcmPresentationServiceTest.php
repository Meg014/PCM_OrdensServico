<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\PcmPresentationService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class PcmPresentationServiceTest extends TestCase
{
    use PcmSnapshotFixture;

    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connection()->begin();
        self::$ids = self::seedValidatedSnapshot();
    }

    public static function tearDownAfterClass(): void
    {
        self::connection()->rollback();
        parent::tearDownAfterClass();
    }

    public function testPayloadContainsOnlyOpenAndCompletedInPresentationOrder(): void
    {
        $payload = (new PcmPresentationService())->payload();

        $this->assertSame(self::$ids['currentImportId'], $payload['import_id']);
        $this->assertSame(['general', 'ELETRI', 'MECANI'], array_column($payload['screens'], 'key'));
        $this->assertSame(
            ['PCM - VISÃO GERAL', 'PCM - ELÉTRICA', 'PCM - MECÂNICA'],
            array_column($payload['screens'], 'title'),
        );
        $this->assertSame(['open' => 158, 'completed' => 371], array_intersect_key(
            $payload['screens'][0],
            ['open' => true, 'completed' => true],
        ));
        $this->assertSame(['open' => 154, 'completed' => 0], array_intersect_key(
            $payload['screens'][1],
            ['open' => true, 'completed' => true],
        ));
        $typeKeys = [
            'preventive' => true,
            'corrective' => true,
            'improvement' => true,
            'blank_maintenance_type' => true,
        ];
        $this->assertSame([
            'preventive' => 40, 'corrective' => 40,
            'improvement' => 39, 'blank_maintenance_type' => 39,
        ], array_intersect_key($payload['screens'][0], $typeKeys));
        $this->assertSame([
            'preventive' => 39, 'corrective' => 39,
            'improvement' => 38, 'blank_maintenance_type' => 38,
        ], array_intersect_key($payload['screens'][1], $typeKeys));
        $this->assertArrayNotHasKey('cancelled', $payload['screens'][0]);
    }
}
