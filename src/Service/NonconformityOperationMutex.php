<?php
declare(strict_types=1);
namespace App\Service;

use Cake\Database\Connection;
use Cake\Http\Exception\ConflictException;

/** Serializes NC commits with SMTP on one installation, without holding a transaction during SMTP. */
final class NonconformityOperationMutex
{
    private static array $depth = [];

    public static function run(Connection $connection, callable $operation): mixed
    {
        $config = $connection->config();
        $key = hash('sha256', implode('|', [(string)($config['driver'] ?? ''), $config['host'] ?? '', $config['port'] ?? '', $config['database'] ?? '']));
        if (isset(self::$depth[$key])) {
            self::$depth[$key]++;
            try { return $operation(); } finally { self::$depth[$key]--; }
        }
        $file = fopen(TMP . 'nc-notification-operation-' . $key . '.lock', 'c');
        if ($file === false) throw new \RuntimeException('Não foi possível abrir o bloqueio de Não Conformidades. Verifique as permissões de tmp.');
        $started = microtime(true);
        try {
            while (!flock($file, LOCK_EX | LOCK_NB)) {
                if (microtime(true) - $started >= 10) throw new ConflictException('Há uma operação de Não Conformidades em andamento. Tente novamente em instantes.');
                usleep(50000);
            }
            self::$depth[$key] = 1;
            try { return $operation(); }
            finally { unset(self::$depth[$key]); flock($file, LOCK_UN); }
        } finally { fclose($file); }
    }
}
