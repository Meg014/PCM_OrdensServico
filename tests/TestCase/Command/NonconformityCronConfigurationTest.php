<?php
declare(strict_types=1);
namespace App\Test\TestCase\Command;

use Cake\TestSuite\TestCase;

final class NonconformityCronConfigurationTest extends TestCase
{
    public function testCronUsesTheActualRootPhpAndServiceUserAndOnlyRendersText(): void
    {
        require_once ROOT . '/deployment/scripts/gerar-cron-nao-conformidades.php';
        $cron = \pcmNcCronContents('/srv/pcm atual', '/usr/bin/php8.3', 'www-data', '/usr/bin/flock');
        self::assertStringContainsString("*/5 * * * * www-data cd '/srv/pcm atual'", $cron);
        self::assertStringContainsString("'/usr/bin/flock' -n '/srv/pcm atual/tmp/nc-email-worker.lock' '/usr/bin/php8.3'", $cron);
        self::assertStringContainsString('nonconformity_alerts --limit 100', $cron);
        self::assertStringEndsWith("\n", $cron);
        self::assertStringNotContainsString('MAIL_PASSWORD', $cron);
    }
    public function testCronRejectsUserInjectionAndPercentInPaths(): void
    {
        require_once ROOT . '/deployment/scripts/gerar-cron-nao-conformidades.php';
        foreach ([['/srv/pcm', '/usr/bin/php', 'www-data;touch /tmp/x', '/usr/bin/flock'], ['/srv/pcm%mail', '/usr/bin/php', 'www-data', '/usr/bin/flock']] as $arguments) {
            try { \pcmNcCronContents(...$arguments); self::fail('Invalid cron accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
