<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service\Protheus;

use App\Service\Protheus\ProtheusUnit;
use PHPUnit\Framework\TestCase;

final class ProtheusUnitTest extends TestCase
{
    public function testOfficialCatalogCountsAndTextCodes(): void
    {
        $records = ProtheusUnit::records();
        self::assertCount(155, $records);
        $counts = [];
        foreach ($records as $row) {
            self::assertIsString($row['code']);
            self::assertIsString($row['protheus_code']);
            self::assertSame($row['unit'], ProtheusUnit::classify($row['code']));
            self::assertSame($row['unit'], ProtheusUnit::classify(" \t" . $row['protheus_code'] . '  '));
            self::assertSame($row['code'], ProtheusUnit::metadata($row['protheus_code'])['code']);
            $counts[$row['unit']] = ($counts[$row['unit']] ?? 0) + 1;
        }
        self::assertSame(['admin' => 39, 'factory' => 23, 'mill' => 36, 'external' => 23, 'sectors' => 34], $counts);
        self::assertCount(11, array_filter($records, static fn ($r) => $r['offseason']));
    }

    public function testUnknownEmptyAndMisleadingPrefixesAreNotAssigned(): void
    {
        foreach ([null, '', ' ', '31ABC', '41 XYZ', '03101004', '3.1.0.1.0.0.04', '4101999', '1201002'] as $code) {
            self::assertSame('other', ProtheusUnit::classify($code));
        }
        self::assertSame('external', ProtheusUnit::classify('4101035'));
        self::assertSame('admin', ProtheusUnit::classify('4'));
        self::assertSame('factory', ProtheusUnit::classify('3.1.0.1.0.0.4'));
    }

    public function testOffseasonCostCentersKeepTheirMainUnitAndDoNotReplaceServiceRules(): void
    {
        $groups = [];
        foreach (ProtheusUnit::records() as $row) if ($row['offseason']) $groups[$row['source_unit']] = ($groups[$row['source_unit']] ?? 0) + 1;
        self::assertSame(['Fabrica - entressafra' => 6, 'Usina - entressafra' => 5], $groups);
        self::assertSame('factory', ProtheusUnit::classify('3101014'));
        self::assertTrue(ProtheusUnit::metadata('3101014')['offseason']);
        self::assertSame('mill', ProtheusUnit::classify('4101030'));
        self::assertTrue(ProtheusUnit::metadata('4101030')['offseason']);
        $classifier = new \App\Service\PcmServiceClassifier();
        self::assertSame('OUTROS', $classifier->classify('PREVEN', 'PREVENTIVA', '01'));
        self::assertSame('ENTRESSAFRA', $classifier->classify('2425EL', 'SERVIÇO', '01'));
    }

    public function testActualSqlMatchesPhpForEveryOfficialCodeAndAlias(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->sqliteCreateCollation('DATABASE_DEFAULT', 'strcmp');
        $sql = 'SELECT ' . ProtheusUnit::expression('c.cc') . ' FROM (SELECT :code AS cc) c';
        // Both engines expose key/value for a JSON object; only the table function differs.
        $statement = $db->prepare(str_replace("OPENJSON(N'", "json_each('", $sql));
        foreach (ProtheusUnit::records() as $row) foreach ([$row['code'], $row['protheus_code']] as $code) {
            $statement->execute(['code' => ' ' . $code . ' ']);
            self::assertSame($row['unit'], $statement->fetchColumn());
        }
        foreach ([null, '', '31UNKNOWN', '0001', '4101999'] as $code) {
            $statement->execute(['code' => $code]);
            self::assertSame('other', $statement->fetchColumn());
        }
    }

    public function testEachUnitSelectsExclusiveRowsAndAllNeverDuplicates(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->sqliteCreateCollation('DATABASE_DEFAULT', 'strcmp');
        $db->exec('CREATE TABLE orders (cc TEXT)');
        $insert = $db->prepare('INSERT INTO orders VALUES (?)');
        foreach (ProtheusUnit::records() as $row) $insert->execute([$row['protheus_code']]);
        $insert->execute(['']); $insert->execute(['unknown']);
        $statement = $db->prepare(str_replace("OPENJSON(N'", "json_each('", 'SELECT COUNT(*) FROM orders j CROSS JOIN (SELECT :unit AS unit) f WHERE ' . ProtheusUnit::predicate('j.cc', 'f.unit')));
        foreach (['' => 157, 'admin' => 39, 'factory' => 23, 'mill' => 36, 'external' => 23, 'sectors' => 34, 'other' => 2] as $unit => $quantity) {
            $statement->execute(['unit' => $unit]);
            self::assertSame($quantity, (int)$statement->fetchColumn());
        }
        self::assertStringNotContainsString('LIKE', ProtheusUnit::predicate('j.cc', 'f.unit'));
    }
}
