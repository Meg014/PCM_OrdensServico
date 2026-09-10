<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use RuntimeException;

final class PcmHealthCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public function testReportsDatabaseAndConfiguredReportDirectoryWithoutSecrets(): void
    {
        $original = Configure::read('Pcm.reports.incoming');
        Configure::write('Pcm.reports.incoming', sys_get_temp_dir());
        try {
            $this->exec('pcm_health');
            $this->assertExitSuccess();
            $this->assertOutputContains('Banco de dados: acessível');
            $this->assertOutputContains('Datasource: default');
            $this->assertOutputContains('Driver: ');
            $this->assertOutputContains('Host: ');
            $this->assertOutputContains('Porta: ');
            $this->assertOutputContains('Banco: ');
            $this->assertOutputContains('Versão do servidor: ');
            $this->assertOutputContains('Pasta de relatórios: acessível para leitura');
            $this->assertOutputNotContains('password');
        } finally {
            Configure::write('Pcm.reports.incoming', $original);
        }
    }

    public function testConnectionFailureDoesNotExposeSecretsOrSkipDirectoryCheck(): void
    {
        $originalPath = Configure::read('Pcm.reports.incoming');
        $originalAlias = ConnectionManager::aliases()['default'] ?? null;
        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([[
                'driver' => Mysql::class,
                'host' => 'db.example.invalid',
                'port' => 3307,
                'database' => 'pcm_health_test',
                'password' => 'health-secret-marker',
            ]])
            ->onlyMethods(['execute'])
            ->getMock();
        $connection->expects($this->once())->method('execute')
            ->willThrowException(new RuntimeException('DSN password=health-secret-marker'));
        ConnectionManager::setConfig('health_failure', $connection);
        ConnectionManager::alias('health_failure', 'default');
        Configure::write('Pcm.reports.incoming', sys_get_temp_dir());
        try {
            $this->exec('pcm_health');
            $this->assertExitError();
            $this->assertOutputContains('Driver: ' . Mysql::class);
            $this->assertOutputContains('Host: db.example.invalid');
            $this->assertOutputContains('Porta: 3307');
            $this->assertOutputContains('Banco: pcm_health_test');
            $this->assertErrorContains('Banco de dados: indisponível. Verifique DB_*');
            $this->assertOutputContains('Pasta de relatórios: acessível para leitura');
            $this->assertOutputNotContains('health-secret-marker');
            $this->assertStringNotContainsString('health-secret-marker', implode("\n", $this->_err->messages()));
        } finally {
            ConnectionManager::dropAlias('default');
            if ($originalAlias !== null) {
                ConnectionManager::alias($originalAlias, 'default');
            }
            ConnectionManager::drop('health_failure');
            Configure::write('Pcm.reports.incoming', $originalPath);
        }
    }

    public function testReportsMissingConfiguredDirectoryAsUnavailable(): void
    {
        $original = Configure::read('Pcm.reports.incoming');
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcm-health-missing-' . bin2hex(random_bytes(8));
        $this->assertDirectoryDoesNotExist($missing);
        Configure::write('Pcm.reports.incoming', $missing);
        try {
            $this->exec('pcm_health');
            $this->assertExitError();
            $this->assertErrorContains('Pasta de relatórios: inexistente ou sem permissão de leitura.');
            $this->assertDirectoryDoesNotExist($missing);
        } finally {
            Configure::write('Pcm.reports.incoming', $original);
        }
    }

    public function testConnectedDatabaseWithoutMigrationsStillReportsDirectory(): void
    {
        $originalPath = Configure::read('Pcm.reports.incoming');
        $originalAlias = ConnectionManager::aliases()['default'] ?? null;
        ConnectionManager::setConfig('health_empty', [
            'className' => Connection::class,
            'driver' => Sqlite::class,
            'database' => ':memory:',
        ]);
        ConnectionManager::alias('health_empty', 'default');
        $this->getTableLocator()->clear();
        Configure::write('Pcm.reports.incoming', sys_get_temp_dir());
        try {
            $this->exec('pcm_health');
            $this->assertExitError();
            $this->assertOutputContains('Banco de dados: acessível');
            $this->assertOutputContains('Versão do servidor: ');
            $this->assertOutputContains('Pasta de relatórios: acessível para leitura');
            $this->assertErrorContains('Verifique as migrations do datasource default.');
        } finally {
            $this->getTableLocator()->clear();
            ConnectionManager::dropAlias('default');
            if ($originalAlias !== null) {
                ConnectionManager::alias($originalAlias, 'default');
            }
            ConnectionManager::drop('health_empty');
            Configure::write('Pcm.reports.incoming', $originalPath);
        }
    }
}
