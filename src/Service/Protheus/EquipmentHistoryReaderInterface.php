<?php
declare(strict_types=1);

namespace App\Service\Protheus;

use App\Service\Protheus\Presentation\EquipmentHistoryPage;

/** Contract only: intentionally no SQL implementation or application registration yet. */
interface EquipmentHistoryReaderInterface
{
    /**
     * Exact equipment/branch scope; do not silently combine branches.
     * Implementation must validate page >= 1 and 1 <= limit <= 100, bind string keys,
     * exclude deleted rows and order in SQL by the validated reference date DESC,
     * null dates last, then a validated unique key for stable pagination.
     * Resolve existing authorized PCM detail targets separately in a future service.
     * Do not hydrate every order's labor/materials just to display a history page.
     */
    public function findByEquipment(string $equipmentCode, string $branch, int $page = 1, int $limit = 20): EquipmentHistoryPage;
}
