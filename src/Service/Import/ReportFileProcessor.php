<?php
declare(strict_types=1);

namespace App\Service\Import;

use Cake\Core\Configure;
use Cake\I18n\Date;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

final class ReportFileProcessor
{
    /** Creates the file workflow around the snapshot importer. */
    public function __construct(private readonly ReportImportService $importService = new ReportImportService())
    {
    }

    /** Validates and processes a manual HTTP upload. */
    public function processUpload(UploadedFileInterface $upload): object
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no upload (código ' . $upload->getError() . ').');
        }
        $name = basename((string)$upload->getClientFilename());
        $this->assertUpload($name, (int)($upload->getSize() ?? 0));
        $staging = $this->createStagingDirectory();
        $path = $staging . DIRECTORY_SEPARATOR . $name;
        $upload->moveTo($path);

        return $this->processStagedFile($path, $name, stagingDirectory: $staging);
    }

    /** Copies and processes an explicitly provided legacy file. */
    public function processFile(string $sourcePath): object
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Arquivo não encontrado: ' . $sourcePath);
        }
        $name = basename($sourcePath);
        $this->assertUpload($name, (int)filesize($sourcePath));
        $staging = $this->createStagingDirectory();
        $path = $staging . DIRECTORY_SEPARATOR . $name;
        if (!copy($sourcePath, $path)) {
            throw new RuntimeException('Não foi possível copiar o arquivo para a pasta de entrada.');
        }

        return $this->processStagedFile($path, $name, stagingDirectory: $staging);
    }

    /** Processes a file already located inside the configured incoming directory. */
    public function processIncomingFile(string $path): object
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo não encontrado: ' . $path);
        }
        $incoming = realpath($this->incomingDirectory());
        $resolved = realpath($path);
        if (
            $incoming === false || $resolved === false || !str_starts_with(
                strtolower($resolved),
                strtolower($incoming . DIRECTORY_SEPARATOR),
            )
        ) {
            throw new RuntimeException('O arquivo pendente não pertence à pasta de entrada configurada.');
        }
        $name = basename($resolved);
        $archiveName = $this->isOperationalFile($name) ? $this->operationalArchiveName($resolved, $name) : null;

        return $this->processReadOnlySource($resolved, $name, $archiveName);
    }

    /** Processes a copy while preserving the fixed operational source file. */
    private function processReadOnlySource(string $sourcePath, string $name, ?string $archiveName = null): object
    {
        $this->assertUpload($name, (int)filesize($sourcePath));
        $hash = hash_file('sha256', $sourcePath);
        if ($hash === false) {
            throw new RuntimeException('Não foi possível calcular o hash do arquivo operacional.');
        }
        if ($this->importService->hasImportedHash($hash)) {
            throw new DuplicateReportException($hash);
        }

        $reportDate = $this->reportDateFromSource($sourcePath);
        $staging = $this->createStagingDirectory();
        $stagedPath = $staging . DIRECTORY_SEPARATOR . $name;
        if (!copy($sourcePath, $stagedPath)) {
            throw new RuntimeException('Não foi possível copiar o arquivo operacional para processamento.');
        }

        return $this->processStagedFile($stagedPath, $name, $reportDate, $archiveName, $staging);
    }

    /** Ensures all configured folders exist before a scheduled scan. */
    public function ensureDirectories(): void
    {
        $this->incomingDirectory();
        foreach (['staging', 'processed', 'error'] as $key) {
            $this->directory($key);
        }
    }

    /** Imports and moves a staged copy to its audit destination. */
    private function processStagedFile(
        string $path,
        string $name,
        ?Date $reportDate = null,
        ?string $archiveName = null,
        ?string $stagingDirectory = null,
    ): object {
        try {
            $result = $this->importService->import($path, $name, $reportDate);
            $this->archive($path, 'processed', (string)$result->file_hash, $archiveName);
            $this->removeEmptyStagingDirectory($stagingDirectory);

            return $result;
        } catch (DuplicateReportException $exception) {
            if (is_file($path)) {
                $this->archive($path, 'processed', $exception->fileHash, $archiveName);
                $this->removeEmptyStagingDirectory($stagingDirectory);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if (is_file($path)) {
                $this->archive(
                    $path,
                    'error',
                    hash_file('sha256', $path) ?: bin2hex(random_bytes(8)),
                    $archiveName,
                );
                $this->removeEmptyStagingDirectory($stagingDirectory);
            }
            throw $exception;
        }
    }

    /** Moves one staged file into a hash-partitioned audit directory. */
    private function archive(string $path, string $destination, string $key, ?string $archiveName = null): void
    {
        $directory = $this->directory($destination) . DIRECTORY_SEPARATOR . substr($key, 0, 16);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta de destino da importação.');
        }
        $target = $this->availableTarget($directory, $archiveName ?? basename($path));
        if (!rename($path, $target)) {
            throw new RuntimeException('Não foi possível mover o relatório para ' . $destination . '.');
        }
    }

    /** Creates a uniquely identified local directory controlled by the application. */
    private function createStagingDirectory(): string
    {
        $staging = $this->directory('staging') . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0775) && !is_dir($staging)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária da importação.');
        }

        return $staging;
    }

    /** Removes only an empty direct child of the configured local staging root. */
    private function removeEmptyStagingDirectory(?string $directory): void
    {
        if ($directory === null || !is_dir($directory)) {
            return;
        }
        $resolved = realpath($directory);
        $stagingRoot = realpath($this->directory('staging'));
        $incoming = realpath($this->incomingDirectory());
        if (
            $resolved === false || $stagingRoot === false || $resolved === $stagingRoot ||
            dirname($resolved) !== $stagingRoot || ($incoming !== false && $resolved === $incoming)
        ) {
            throw new RuntimeException('Recusa de remover diretório que não pertence ao staging da aplicação.');
        }
        if (count(scandir($resolved) ?: []) === 2 && !rmdir($resolved)) {
            throw new RuntimeException('Não foi possível remover a pasta temporária vazia.');
        }
    }

    /** Resolves the source timestamp before copying it to local staging. */
    private function reportDateFromSource(string $sourcePath): Date
    {
        $modifiedAt = filemtime($sourcePath);
        if ($modifiedAt === false) {
            throw new RuntimeException('Não foi possível determinar a data do arquivo operacional.');
        }
        $timezone = new DateTimeZone((string)Configure::read('App.displayTimezone', 'America/Sao_Paulo'));

        return new Date((new DateTimeImmutable('@' . $modifiedAt))->setTimezone($timezone));
    }

    /** Builds the historical name used only for a configured fixed operational file. */
    private function operationalArchiveName(string $sourcePath, string $name): string
    {
        $modifiedAt = filemtime($sourcePath);
        if ($modifiedAt === false) {
            throw new RuntimeException('Não foi possível determinar a data do arquivo operacional.');
        }
        $timezone = new DateTimeZone((string)Configure::read('App.displayTimezone', 'America/Sao_Paulo'));
        $timestamp = (new DateTimeImmutable('@' . $modifiedAt))->setTimezone($timezone);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return 'Relatorio_OS_' . $timestamp->format('Y-m-d_His') . '.' . $extension;
    }

    /** Matches the configured fixed operational file name case-insensitively. */
    private function isOperationalFile(string $name): bool
    {
        $configured = (string)Configure::read('Pcm.reports.operationalFile', '');

        return $configured !== '' && strcasecmp($name, basename($configured)) === 0;
    }

    /** Produces a collision-free archive target. */
    private function availableTarget(string $directory, string $name): string
    {
        $target = $directory . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($target)) {
            return $target;
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        for ($index = 2; $index < 1000; $index++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $base . '-' . $index . ($extension ? '.' . $extension : '');
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Não foi possível gerar um nome de arquivo de destino disponível.');
    }

    /** Resolves and creates one configured PCM directory. */
    private function directory(string $key): string
    {
        $directory = (string)Configure::read("Pcm.reports.{$key}");
        if ($directory === '') {
            throw new RuntimeException("Diretório PCM {$key} não configurado.");
        }
        $incoming = (string)Configure::read('Pcm.reports.incoming', '');
        $normalizedDirectory = strtolower(str_replace('/', '\\', rtrim($directory, '\\/')));
        $normalizedIncoming = strtolower(str_replace('/', '\\', rtrim($incoming, '\\/')));
        if (
            $key !== 'incoming' && $normalizedIncoming !== '' &&
            ($normalizedDirectory === $normalizedIncoming || str_starts_with(
                $normalizedDirectory,
                $normalizedIncoming . '\\',
            ))
        ) {
            throw new RuntimeException("O diretório local {$key} não pode ficar dentro de PCM_REPORT_PATH.");
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Não foi possível criar o diretório {$directory}.");
        }

        return $directory;
    }

    /** Returns the official read-only source directory without ever creating it. */
    private function incomingDirectory(): string
    {
        $directory = (string)Configure::read('Pcm.reports.incoming');
        if ($directory === '' || !is_dir($directory)) {
            throw new RuntimeException('A pasta oficial de relatórios não existe ou está sem permissão de leitura.');
        }

        return $directory;
    }

    /** Validates extension and configured size limit. */
    private function assertUpload(string $name, int $size): void
    {
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'xlsx'], true)) {
            throw new RuntimeException('Somente arquivos .csv ou .xlsx são aceitos.');
        }
        $max = (int)Configure::read('Pcm.reports.maxUploadBytes', 20 * 1024 * 1024);
        if ($size <= 0 || $size > $max) {
            throw new RuntimeException('Tamanho de arquivo inválido ou superior ao limite configurado.');
        }
    }
}
