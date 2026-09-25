<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\DataTransfer;

use App\Service\DataTransfer\PcmDataTransferService;
use App\Service\DataTransfer\PcmTransferSchema;
use App\Service\DataTransfer\PostgresSequenceSynchronizer;
use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Database\Driver\Sqlite;
use Cake\TestSuite\TestCase;
use RuntimeException;

final class PcmDataTransferServiceTest extends TestCase
{
    private Connection $source;
    private Connection $destination;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = $this->database();
        $this->destination = $this->database();
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcm-transfer-' . bin2hex(random_bytes(8)) . '.jsonl';
        $this->seed($this->source);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        $this->source->getDriver()->disconnect();
        $this->destination->getDriver()->disconnect();
        parent::tearDown();
    }

    public function testRoundTripPreservesIdsPasswordHashesTypesAndRelationships(): void
    {
        $sourceService = new PcmDataTransferService($this->source);
        $counts = $sourceService->export($this->file);
        $this->assertSame(1, $counts['users']);
        $this->assertSame(1, $counts['work_order_snapshots']);

        $imported = (new PcmDataTransferService($this->destination))->import($this->file);
        $this->assertSame($counts, $imported);
        $verification = (new PcmDataTransferService($this->destination))->verify($this->file);
        $this->assertTrue($verification['valid']);

        $user = $this->destination->execute('SELECT * FROM users')->fetch('assoc');
        $this->assertSame(71, (int)$user['id']);
        $this->assertSame('$2y$10$already.hashed.and.never.reprocessed', $user['password']);
        $this->assertSame(1, (int)$user['must_change_password']);
        $snapshot = $this->destination->execute('SELECT * FROM work_order_snapshots')->fetch('assoc');
        $this->assertSame(91, (int)$snapshot['id']);
        $this->assertEquals(123.45, $snapshot['labor_cost']);
        $this->assertNull($snapshot['finished_at']);
        $this->assertJsonStringEqualsJsonString('{"nested":{"b":2,"a":1}}', $snapshot['raw_payload']);

        $this->destination->insert('equipment', [
            'branch_code' => '1', 'source_code' => 'NEXT', 'name' => 'Next',
            'is_generic' => false, 'active' => true, 'created' => '2026-09-25 10:00:00',
            'updated' => null,
        ]);
        $this->assertGreaterThan(41, (int)$this->destination->getDriver()->lastInsertId());
    }

    public function testImportRefusesNonEmptyDestination(): void
    {
        (new PcmDataTransferService($this->source))->export($this->file);
        $this->destination->insert('maintenance_areas', [
            'id' => 999, 'source_code' => 'EXISTE', 'display_name' => 'Existe', 'slug' => 'existe',
            'active' => true, 'created' => '2026-09-25 10:00:00', 'updated' => null,
        ]);

        $this->expectExceptionMessage('Destino não está vazio');
        (new PcmDataTransferService($this->destination))->import($this->file);
    }

    public function testMalformedFileRollsBackEveryInsertedTable(): void
    {
        (new PcmDataTransferService($this->source))->export($this->file);
        file_put_contents($this->file, "{}\n", FILE_APPEND);

        try {
            (new PcmDataTransferService($this->destination))->import($this->file);
            $this->fail('A importação adulterada deveria falhar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Conteúdo inesperado', $exception->getMessage());
        }
        foreach (PcmTransferSchema::TABLES as $table) {
            $this->assertSame(0, (int)$this->destination->execute("SELECT COUNT(*) FROM {$table}")->fetchColumn(0));
        }
    }

    public function testVerificationDetectsDataDivergence(): void
    {
        (new PcmDataTransferService($this->source))->export($this->file);
        (new PcmDataTransferService($this->destination))->import($this->file);
        $this->destination->update('users', ['password' => 'changed'], ['id' => 71]);

        $result = (new PcmDataTransferService($this->destination))->verify($this->file);
        $this->assertFalse($result['valid']);
        $this->assertFalse($result['tables']['users']['valid']);
    }

    public function testPostgresSequenceRestartIsTransactionalAndIdentifierSafe(): void
    {
        $service = new PostgresSequenceSynchronizer();
        $sql = $service->restartStatement('public.work_orders_id_seq', 62, new Postgres());
        $this->assertSame('ALTER SEQUENCE "public"."work_orders_id_seq" RESTART WITH 62', $sql);

        $this->expectExceptionMessage('Nome de sequence');
        $service->restartStatement('public.sequence; DROP TABLE users', 1, new Postgres());
    }

    private function database(): Connection
    {
        $connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
        $connection->execute('PRAGMA foreign_keys = ON');
        foreach ($this->schemaSql() as $sql) {
            $connection->execute($sql);
        }

        return $connection;
    }

    private function schemaSql(): array
    {
        return [
            'CREATE TABLE maintenance_areas (id INTEGER PRIMARY KEY AUTOINCREMENT, source_code VARCHAR(30) NOT NULL UNIQUE, display_name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, active BOOLEAN NOT NULL, created DATETIME NOT NULL, updated DATETIME NULL)',
            'CREATE TABLE cost_centers (id INTEGER PRIMARY KEY AUTOINCREMENT, source_code VARCHAR(30) NOT NULL UNIQUE, name VARCHAR(255) NULL, active BOOLEAN NOT NULL, created DATETIME NOT NULL, updated DATETIME NULL)',
            'CREATE TABLE equipment (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_code VARCHAR(10) NOT NULL, source_code VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, is_generic BOOLEAN NOT NULL, active BOOLEAN NOT NULL, created DATETIME NOT NULL, updated DATETIME NULL)',
            'CREATE TABLE services (id INTEGER PRIMARY KEY AUTOINCREMENT, source_code VARCHAR(30) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL, pcm_category VARCHAR(30) NULL, classification_version INTEGER NOT NULL, active BOOLEAN NOT NULL, created DATETIME NOT NULL, updated DATETIME NULL)',
            'CREATE TABLE report_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, file_name VARCHAR(255) NOT NULL, file_path VARCHAR(1024) NOT NULL, report_date DATE NOT NULL, file_hash VARCHAR(64) NOT NULL UNIQUE, file_size BIGINT NOT NULL, sheet_name VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, rows_read INTEGER NOT NULL, rows_imported INTEGER NOT NULL, rows_rejected INTEGER NOT NULL, warning_count INTEGER NOT NULL, error_count INTEGER NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, error_message TEXT NULL, header_signature VARCHAR(64) NULL, metadata JSON NULL, created DATETIME NOT NULL, updated DATETIME NULL)',
            'CREATE TABLE work_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_code VARCHAR(10) NOT NULL, source_order_number VARCHAR(30) NOT NULL, equipment_id INTEGER NULL REFERENCES equipment(id), first_seen_report_date DATE NOT NULL, last_seen_report_date DATE NOT NULL, created DATETIME NOT NULL, updated DATETIME NULL)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, nome VARCHAR(150) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, maintenance_area_id INTEGER NULL REFERENCES maintenance_areas(id), ativo BOOLEAN NOT NULL, created DATETIME NOT NULL, modified DATETIME NOT NULL, must_change_password BOOLEAN NOT NULL)',
            'CREATE TABLE work_order_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, work_order_id INTEGER NOT NULL REFERENCES work_orders(id), report_import_id INTEGER NOT NULL REFERENCES report_imports(id), maintenance_area_id INTEGER NULL REFERENCES maintenance_areas(id), equipment_id INTEGER NULL REFERENCES equipment(id), service_id INTEGER NULL REFERENCES services(id), cost_center_id INTEGER NULL REFERENCES cost_centers(id), labor_cost DECIMAL(15,4) NULL, raw_payload JSON NOT NULL, validation_warnings JSON NULL, finished_at DATETIME NULL, created DATETIME NOT NULL)',
            'CREATE TABLE tv_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id), token_hash VARCHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL)',
        ];
    }

    private function seed(Connection $connection): void
    {
        $time = '2026-09-25 08:30:00';
        $connection->insert('maintenance_areas', ['id' => 11, 'source_code' => 'MECANI', 'display_name' => 'Mecânica', 'slug' => 'mecanica', 'active' => true, 'created' => $time, 'updated' => null]);
        $connection->insert('cost_centers', ['id' => 21, 'source_code' => 'CC1', 'name' => null, 'active' => true, 'created' => $time, 'updated' => null]);
        $connection->insert('equipment', ['id' => 41, 'branch_code' => '1', 'source_code' => 'EQ1', 'name' => 'Bomba', 'is_generic' => false, 'active' => true, 'created' => $time, 'updated' => null]);
        $connection->insert('services', ['id' => 31, 'source_code' => 'SRV1', 'name' => 'Serviço', 'pcm_category' => null, 'classification_version' => 1, 'active' => true, 'created' => $time, 'updated' => null]);
        $connection->insert('report_imports', ['id' => 51, 'file_name' => 'legacy.xlsx', 'file_path' => 'C:\\legacy\\legacy.xlsx', 'report_date' => '2026-09-24', 'file_hash' => str_repeat('a', 64), 'file_size' => 123, 'sheet_name' => 'sclxd280', 'status' => 'success', 'rows_read' => 1, 'rows_imported' => 1, 'rows_rejected' => 0, 'warning_count' => 0, 'error_count' => 0, 'started_at' => $time, 'finished_at' => null, 'error_message' => null, 'header_signature' => null, 'metadata' => '{"rule":4}', 'created' => $time, 'updated' => null]);
        $connection->insert('work_orders', ['id' => 61, 'branch_code' => '1', 'source_order_number' => '100', 'equipment_id' => 41, 'first_seen_report_date' => '2026-09-24', 'last_seen_report_date' => '2026-09-24', 'created' => $time, 'updated' => null]);
        $connection->insert('users', ['id' => 71, 'nome' => 'Admin', 'email' => 'admin@example.invalid', 'password' => '$2y$10$already.hashed.and.never.reprocessed', 'role' => 'ADMIN', 'maintenance_area_id' => 11, 'ativo' => true, 'created' => $time, 'modified' => $time, 'must_change_password' => true]);
        $connection->insert('work_order_snapshots', ['id' => 91, 'work_order_id' => 61, 'report_import_id' => 51, 'maintenance_area_id' => 11, 'equipment_id' => 41, 'service_id' => 31, 'cost_center_id' => 21, 'labor_cost' => '123.4500', 'raw_payload' => '{"nested":{"b":2,"a":1}}', 'validation_warnings' => null, 'finished_at' => null, 'created' => $time]);
        $connection->insert('tv_devices', ['id' => 81, 'user_id' => 71, 'token_hash' => str_repeat('b', 64), 'expires_at' => '2026-12-01 00:00:00']);
    }
}
