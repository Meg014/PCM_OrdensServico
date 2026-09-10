<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Import;

use App\Service\Import\TotvsValueNormalizer;
use Cake\TestSuite\TestCase;

final class TotvsValueNormalizerTest extends TestCase
{
    public function testInvalidTotvsDateMarkersBecomeNull(): void
    {
        $normalizer = new TotvsValueNormalizer();
        $this->assertNull($normalizer->date("\u{00A0}/  /\u{200B}"));
        $this->assertNull($normalizer->combineDateTime('/ /', ':'));
    }

    public function testCodePreservesInternalSpacing(): void
    {
        $this->assertSame('FAB  01', (new TotvsValueNormalizer())->code(' FAB  01 '));
    }
}
