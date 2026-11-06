<?php
declare(strict_types=1);
namespace App\Test\TestCase\Service;

use App\Service\NonconformityOperationMutex;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

final class NonconformityOperationMutexTest extends TestCase
{
    public function testNestedOperationsAreReentrantAndReleaseAfterFailure(): void
    {
        $c = ConnectionManager::get('test');
        try {
            NonconformityOperationMutex::run($c, static fn () => NonconformityOperationMutex::run($c, static function () { throw new \RuntimeException('rollback'); }));
            self::fail();
        } catch (\RuntimeException $e) { self::assertSame('rollback', $e->getMessage()); }
        self::assertSame('released', NonconformityOperationMutex::run($c, static fn () => 'released'));
    }
    public function testAnotherProcessCannotEnterDuringAnNcOperation(): void
    {
        $c = ConnectionManager::get('test');
        $config = array_intersect_key($c->config(), array_flip(['driver', 'host', 'port', 'database']));
        $script = TMP . 'nc-lock-child-' . bin2hex(random_bytes(6)) . '.php';
        $marker = $script . '.entered';
        $source = '<?php require ' . var_export(ROOT . '/vendor/autoload.php', true) . '; define("TMP", ' . var_export(TMP, true) . ');'
            . '$c = new \\Cake\\Database\\Connection(' . var_export($config, true) . '); echo "waiting\\n"; flush();'
            . '\\App\\Service\\NonconformityOperationMutex::run($c, static function () { file_put_contents(' . var_export($marker, true) . ', "entered"); });';
        file_put_contents($script, $source); $process = null; $pipes = [];
        try {
            NonconformityOperationMutex::run($c, function () use ($script, $marker, &$process, &$pipes) {
                $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); fclose($pipes[0]); stream_set_timeout($pipes[1], 3);
                self::assertSame("waiting\n", fgets($pipes[1]));
                usleep(150000); self::assertFileDoesNotExist($marker);
            });
            stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($process); $process = null;
            self::assertSame(0, $code, $stderr); self::assertSame('entered', file_get_contents($marker));
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_file($script)) unlink($script); if (is_file($marker)) unlink($marker);
        }
    }
}
