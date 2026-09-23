<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

/** Separate from PCM snapshot audit. Input must be an already ordered, bounded page. */
final readonly class EquipmentHistoryPage
{
    /**
     * @param list<array{number: string, branch: string, reference_date: ?string,
     *   service_code: ?string, service_name: ?string, pcm_snapshot_id: ?int}> $orders
     * reference_date: ISO datetime, semantic source to be validated before implementing SQL.
     * pcm_snapshot_id: resolved/authorized local snapshot ID, never the Protheus OS number.
     * null ID means no existing PCM detail link is available.
     */
    public function __construct(
        public string $equipmentCode,
        public array $orders = [],
        public bool $hasMore = false,
        public Availability $availability = Availability::Disabled,
    ) {
    }

    public function present(): array
    {
        $items = [];
        if ($this->availability === Availability::Available) {
            foreach ($this->orders as $order) {
                $id = $order['pcm_snapshot_id'] ?? null;
                $items[] = [
                    'number' => $order['number'],
                    'branch' => $order['branch'],
                    'reference_date' => $order['reference_date'] ?? null,
                    'service_code' => $order['service_code'] ?? null,
                    'service_name' => $order['service_name'] ?? null,
                    'detail_route' => is_int($id) && $id > 0 ? ['_name' => 'pcm-order', 'id' => $id] : null,
                ];
            }
        }

        return [
            'equipment_code' => $this->equipmentCode,
            'state' => $this->availability->value,
            'orders' => $items,
            'has_more' => $this->availability === Availability::Available && $this->hasMore,
        ];
    }
}
