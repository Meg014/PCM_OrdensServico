<?php
declare(strict_types=1);

namespace App\Test\TestCase\Migrations;

use Cake\TestSuite\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class PostgresPortabilityTest extends TestCase
{
    public function testMigrationsDoNotContainMysqlOnlySql(): void
    {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CONFIG . 'Migrations')),
            '/\.php$/',
        );
        $mysqlOnly = '/\b(?:AUTO_INCREMENT|ENGINE\s*=|UNSIGNED\b|TINYINT\b|MEDIUMINT\b|'
            . 'INSERT\s+IGNORE|REPLACE\s+INTO|ON\s+DUPLICATE\s+KEY|GROUP_CONCAT|IFNULL|DATE_FORMAT)\b/i';
        $checked = 0;

        foreach ($files as $file) {
            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression($mysqlOnly, $source, $file->getFilename());
            $checked++;
        }

        $this->assertSame(10, $checked, 'Toda migration existente deve participar da auditoria.');
    }
}
