<?php
declare(strict_types=1);

namespace App\Service\DataTransfer;

final class PcmTransferSchema
{
    public const FORMAT = 'pcm-logical-transfer';
    public const VERSION = 1;

    /** @var list<string> */
    public const TABLES = [
        'maintenance_areas',
        'cost_centers',
        'equipment',
        'services',
        'report_imports',
        'work_orders',
        'users',
        'work_order_snapshots',
        'tv_devices',
    ];

    /** @var array<string, array<string, string>> */
    public const RELATIONSHIPS = [
        'work_orders' => ['equipment_id' => 'equipment'],
        'users' => ['maintenance_area_id' => 'maintenance_areas'],
        'work_order_snapshots' => [
            'work_order_id' => 'work_orders',
            'report_import_id' => 'report_imports',
            'maintenance_area_id' => 'maintenance_areas',
            'equipment_id' => 'equipment',
            'service_id' => 'services',
            'cost_center_id' => 'cost_centers',
        ],
        'tv_devices' => ['user_id' => 'users'],
    ];

    /** @var array<string, list<string>> */
    public const JSON_COLUMNS = [
        'report_imports' => ['metadata'],
        'work_order_snapshots' => ['raw_payload', 'validation_warnings'],
    ];

    /** Static schema definition; this class cannot be instantiated. */
    private function __construct()
    {
    }
}
