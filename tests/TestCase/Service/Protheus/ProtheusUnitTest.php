<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\ProtheusUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtheusUnitTest extends TestCase
{
    public static function classifications(): array
    {
        return [
            ['3101004', 'factory'], [' 31ABC ', 'factory'], ['4101002', 'mill'], ['41 XYZ', 'mill'],
            ['1201002', 'other'], ['', 'other'], ['   ', 'other'], [null, 'other'],
        ];
    }

    #[DataProvider('classifications')]
    public function testClassifiesOnlyFromTheOrderCostCenter(?string $costCenter, string $expected): void
    {
        self::assertSame($expected, ProtheusUnit::classify($costCenter));
    }

    public function testSharedPredicateCoversAllAndTheThreeExclusiveUnits(): void
    {
        $sql = ProtheusUnit::predicate('n.TJ_CCUSTO', 'f.unit');
        foreach (["f.unit = ''", "f.unit = 'factory'", "f.unit = 'mill'", "f.unit = 'other'",
            "LIKE '31%'", "LIKE '41%'", "NOT LIKE '31%'", "NOT LIKE '41%'"] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
        self::assertSame(['factory', 'mill', 'other'], array_keys(ProtheusUnit::LABELS));
    }
}
