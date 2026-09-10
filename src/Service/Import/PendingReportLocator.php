<?php
declare(strict_types=1);

namespace App\Service\Import;

use Cake\Core\Configure;
use RuntimeException;

final class PendingReportLocator
{
    /** @return list<array{path:string,name:string,ready:bool,reason:?string}> */
    public function scan(): array
    {
        $directory = (string)Configure::read('Pcm.reports.incoming');
        if ($directory === '') {
            throw new RuntimeException('Diretório PCM incoming não configurado.');
        }
        if (!is_dir($directory)) {
            throw new RuntimeException('A pasta oficial de relatórios não existe ou está sem permissão de leitura.');
        }
        $minimumAge = max(0, (int)Configure::read('Pcm.reports.minimumFileAgeSeconds', 60));
        $files = glob($directory . DIRECTORY_SEPARATOR . '*') ?: [];
        usort($files, static function (string $left, string $right): int {
            $byModifiedAt = (filemtime($left) ?: 0) <=> (filemtime($right) ?: 0);

            return $byModifiedAt !== 0 ? $byModifiedAt : strnatcasecmp($left, $right);
        });
        $result = [];
        foreach ($files as $path) {
            $name = basename($path);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!is_file($path) || str_starts_with($name, '~$') || !in_array($extension, ['csv', 'xlsx'], true)) {
                continue;
            }
            $ready = true;
            $reason = null;
            $modified = filemtime($path);
            if ($modified === false || time() - $modified < $minimumAge) {
                $ready = false;
                $reason = 'arquivo ainda recente; será verificado na próxima execução';
            } elseif (!$this->canLock($path)) {
                $ready = false;
                $reason = 'arquivo ainda está em uso; será verificado na próxima execução';
            }
            $result[] = compact('path', 'name', 'ready', 'reason');
        }

        return $result;
    }

    /** Checks that the producer no longer holds the file open. */
    private function canLock(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $locked = flock($handle, LOCK_EX | LOCK_NB);
        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $locked;
    }
}
