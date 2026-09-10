<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\CurrentSnapshotService;
use App\Service\PcmIndicatorService;
use App\Test\TestCase\Support\PcmSnapshotFixture;
use Cake\TestSuite\TestCase;

final class CurrentPortfolioReplacementTest extends TestCase
{
    use PcmSnapshotFixture;

    public function testNewSnapshotReplacesPreviousSnapshotInsteadOfAccumulatingIt(): void
    {
        self::connection()->begin();
        try {
            self::clearPcmData();
            $snapshotA = self::insertImport('2026-08-27', str_repeat('d', 64));
            $snapshotB = self::insertImport('2026-08-28', str_repeat('e', 64));

            for ($number = 1; $number <= 120; $number++) {
                self::connection()->insert('work_orders', [
                    'branch_code' => '1',
                    'source_order_number' => (string)$number,
                    'first_seen_report_date' => '2026-08-27',
                    'last_seen_report_date' => '2026-08-28',
                    'created' => '2026-08-28 08:00:00',
                    'updated' => '2026-08-28 08:00:00',
                ]);
                $workOrderId = (int)self::connection()->getDriver()->lastInsertId();
                if ($number <= 100) {
                    $this->insertOpenSnapshot($snapshotA, $workOrderId, $number, '2026-08-27');
                }
                $this->insertOpenSnapshot($snapshotB, $workOrderId, $number, '2026-08-28');
            }

            $current = new CurrentSnapshotService();
            $this->assertSame(120, $current->query()?->count());
            $this->assertSame(120, (new PcmIndicatorService($current))->calculate()['total']);
            $this->assertSame(220, (int)self::connection()->execute(
                'SELECT COUNT(*) FROM work_order_snapshots',
            )->fetchColumn(0));
        } finally {
            self::connection()->rollback();
        }
    }

    private function insertOpenSnapshot(int $importId, int $workOrderId, int $row, string $date): void
    {
        self::connection()->insert('work_order_snapshots', [
            'work_order_id' => $workOrderId,
            'report_import_id' => $importId,
            'report_date' => $date,
            'branch_code' => '1',
            'source_order_number' => (string)$row,
            'finished_raw' => 'Não',
            'source_situation' => 'Liberado',
            'treated_status' => 'EM ABERTO',
            'status_rule_version' => 4,
            'raw_payload' => '{}',
            'source_row_number' => $row,
            'row_hash' => hash('sha256', "{$importId}|{$row}"),
            'created' => "{$date} 08:00:00",
            'updated' => "{$date} 08:00:00",
        ]);
    }
}
