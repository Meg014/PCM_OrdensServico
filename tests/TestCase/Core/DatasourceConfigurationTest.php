<?php
declare(strict_types=1);

namespace App\Test\TestCase\Core;

use Cake\Database\Driver\Mysql;
use Cake\TestSuite\TestCase;
use josegonzalez\Dotenv\Loader;

final class DatasourceConfigurationTest extends TestCase
{
    public function testSharedEnvironmentOverridesDifferentProcessValues(): void
    {
        $values = [
            'DB_HOST' => 'db.example.invalid',
            'DB_PORT' => '3307',
            'DB_DATABASE' => 'pcm_external_test',
            'DB_USERNAME' => 'pcm_app_test',
            'DB_PASSWORD' => 'test-only-secret',
            'PCM_REPORT_PATH' => '\\\\server\\reports with spaces',
        ];
        $original = [];
        foreach ($values as $key => $value) {
            $original[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $file = tempnam(sys_get_temp_dir(), 'pcm-env-');
        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key . "='" . $value . "'";
        }
        file_put_contents($file, implode("\n", $lines));
        try {
            foreach (['cli-inherited-value', 'web-inherited-value'] as $inherited) {
                foreach ($values as $key => $value) {
                    $_ENV[$key] = $_SERVER[$key] = $inherited;
                    putenv($key . '=' . $inherited);
                }
                (new Loader([$file]))->parse()->putenv(true)->toEnv(true)->toServer(true);
                $config = require CONFIG . 'app.php';
                $default = $config['Datasources']['default'];
                $this->assertSame(Mysql::class, $default['driver']);
                $this->assertSame('utf8mb4', $default['encoding']);
                $this->assertSame($values['DB_HOST'], $default['host']);
                $this->assertSame(3307, $default['port']);
                $this->assertSame($values['DB_DATABASE'], $default['database']);
                $this->assertSame($values['DB_USERNAME'], $default['username']);
                $this->assertSame($values['DB_PASSWORD'], $default['password']);
                $this->assertArrayNotHasKey('url', $default);
                $local = require CONFIG . 'app_local.example.php';
                $this->assertArrayNotHasKey('default', $local['Datasources']);
                $this->assertSame($values['PCM_REPORT_PATH'], $local['Pcm']['reports']['incoming']);
            }
        } finally {
            unlink($file);
            foreach ($original as $key => [$environment, $server, $process]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($environment !== null) {
                    $_ENV[$key] = $environment;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                putenv($process === false ? $key : $key . '=' . $process);
            }
        }
    }
}
