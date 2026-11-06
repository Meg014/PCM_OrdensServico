<?php
declare(strict_types=1);

/** Render only. This utility never installs a cron, reads .env or calls the worker. */
function pcmNcCronContents(string $root, string $php, string $user, string $flock): string
{
    foreach ([$root, $php, $flock] as $path) {
        if (!str_starts_with($path, '/') || preg_match('/[\r\n\x00%]/', $path)) throw new InvalidArgumentException('Use caminhos absolutos Linux, sem quebras de linha ou %.');
    }
    if (!preg_match('/^[a-z_][a-z0-9_-]*\$?$/iD', $user)) throw new InvalidArgumentException('Informe um usuário de serviço válido.');
    $root = rtrim($root, '/');
    $quote = static fn (string $value): string => "'" . str_replace("'", "'\\''", $value) . "'";
    $line = '*/5 * * * * ' . $user . ' cd ' . $quote($root) . ' && ' . $quote($flock) . ' -n '
        . $quote($root . '/tmp/nc-email-worker.lock') . ' ' . $quote($php) . ' bin/cake.php nonconformity_alerts --limit 100 >> '
        . $quote($root . '/logs/nc-email-cron.log') . ' 2>&1';
    return "# Gerado para revisão. Instalar em /etc/cron.d/ somente após autorização.\nSHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n" . $line . "\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    if (PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('Execute este gerador no Ubuntu após a atualização; nenhum arquivo de cron foi instalado.');
    $options = getopt('', ['root:', 'php:', 'user:', 'flock:']);
    $root = (string)($options['root'] ?? dirname(__DIR__, 2));
    $php = (string)($options['php'] ?? PHP_BINARY);
    $user = (string)($options['user'] ?? '');
    $flock = (string)($options['flock'] ?? '/usr/bin/flock');
    if (!is_file($root . '/bin/cake.php')) throw new RuntimeException('A raiz informada não contém bin/cake.php.');
    if (!is_executable($php) || !is_executable($flock)) throw new RuntimeException('Confira os executáveis PHP CLI e flock.');
    if (function_exists('posix_getpwnam') && posix_getpwnam($user) === false) throw new RuntimeException('Usuário de serviço não encontrado.');
    echo pcmNcCronContents($root, $php, $user, $flock);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
}
