<?php
declare(strict_types=1);

namespace App\Test\Integration;

use App\Model\Entity\WorkOrderSnapshot;
use App\Model\Table\WorkOrderSnapshotsTable;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Datasource\ConnectionManager;
use Migrations\Db\Adapter\AdapterInterface;
use Migrations\Db\Adapter\MysqlAdapter;
use PHPUnit\Framework\TestCase;
use RenameSnapshotSourceRowNumber;

/** Opt-in integration test: all writes target a session-local temporary table. */
final class MariaDbSourceRowNumberTest extends TestCase
{
    public function testRenamePreservesDataAndOrmInsertsWithoutQuoting(): void
    {
        $config = ConnectionManager::get('default')->config();
        if ($config['driver'] !== Mysql::class) {
            $this->markTestSkipped('Requires MariaDB/MySQL and CREATE TEMPORARY TABLES permission.');
        }
        $connection = new Connection(array_replace($config, [
            'persistent' => false,
            'cacheMetadata' => false,
            'quoteIdentifiers' => false,
        ]));
        try {
            // This shadows the persistent table only for this private connection.
            // Copy the definition without foreign keys, which temporary tables do not support.
            $ddl = $connection->execute('SHOW CREATE TABLE work_order_snapshots')->fetch('assoc')['Create Table'];
            $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $ddl);
            $ddl = preg_replace('/^\h*CONSTRAINT .* FOREIGN KEY .*\R/m', '', $ddl);
            $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
            $connection->execute($ddl);
            $adapter = new class (['connection' => $connection]) extends MysqlAdapter {
                /** Avoids creating/upgrading migration bookkeeping on the real database. */
                public function setConnection(Connection $connection): AdapterInterface
                {
                    $this->connection = $connection;

                    return $this;
                }
            };
            $columns = $connection->execute('SHOW COLUMNS FROM work_order_snapshots')->fetchAll('assoc');
            if (in_array('source_row_number', array_column($columns, 'Field'), true)) {
                $adapter->renameColumn('work_order_snapshots', 'source_row_number', 'row_number');
            }
            $payload = json_encode(['original' => 'preserved', 'position' => 4], JSON_THROW_ON_ERROR);
            $data = [
                'work_order_id' => 1,
                'report_import_id' => 1,
                'report_date' => '2026-09-10',
                'branch_code' => 'TEST',
                'source_order_number' => 'TEMP-1',
                'treated_status' => 'EM ABERTO',
                'status_rule_version' => 4,
                'raw_payload' => $payload,
                'row_number' => 4,
                'row_hash' => str_repeat('a', 64),
                'created' => '2026-09-10 12:00:00',
                'updated' => '2026-09-10 12:00:00',
            ];
            $quotedColumns = array_map($connection->getDriver()->quoteIdentifier(...), array_keys($data));
            $connection->execute(
                'INSERT INTO work_order_snapshots (' . implode(', ', $quotedColumns) . ') VALUES (' .
                implode(', ', array_fill(0, count($data), '?')) . ')',
                array_values($data),
            );
            $before = $connection->execute('SELECT * FROM work_order_snapshots')->fetch('assoc');
            $definition = $connection->execute("SHOW COLUMNS FROM work_order_snapshots LIKE 'row_number'")
                ->fetch('assoc');

            require_once CONFIG . 'Migrations/20260910000000_RenameSnapshotSourceRowNumber.php';
            (new RenameSnapshotSourceRowNumber())->setAdapter($adapter)->change();

            $after = $connection->execute('SELECT * FROM work_order_snapshots')->fetch('assoc');
            $before['source_row_number'] = $before['row_number'];
            unset($before['row_number']);
            ksort($before);
            ksort($after);
            $this->assertSame($before, $after, 'Every stored value must survive the rename unchanged.');
            $renamedDefinition = $connection
                ->execute("SHOW COLUMNS FROM work_order_snapshots LIKE 'source_row_number'")->fetch('assoc');
            $definition['Field'] = 'source_row_number';
            $this->assertSame($definition, $renamedDefinition);

            $table = new WorkOrderSnapshotsTable(['connection' => $connection]);
            $this->assertContains('source_row_number', $table->getSchema()->columns());
            $this->assertNotContains('row_number', $table->getSchema()->columns());
            unset($data['row_number']);
            $data['source_row_number'] = 57;
            $data['work_order_id'] = 2;
            $data['source_order_number'] = 'TEMP-2';
            $data['raw_payload'] = ['original' => 'preserved', 'position' => 57];
            $data['row_hash'] = str_repeat('b', 64);
            $entity = $table->newEntity($data);
            $this->assertInstanceOf(WorkOrderSnapshot::class, $entity);
            $table->saveOrFail($entity);
            $stored = $table->get($entity->id);
            $this->assertSame(57, $stored->source_row_number);
            $this->assertSame($data['raw_payload'], $stored->raw_payload);
            $this->assertSame($data['row_hash'], $stored->row_hash);
            $this->assertSame(2, $table->find()->count());
        } finally {
            // Disconnect discards the temporary table; no DROP or persistent DML.
            $connection->getDriver()->disconnect();
        }
    }
}
