<?php
declare(strict_types=1);

namespace App\Service\Protheus;

interface ProtheusReaderInterface
{
    public function findOrder(string $numero, ?string $filial = null): ?array;

    public function findOrderIdentity(string $numero, string $filial): ?array;

    public function findEquipmentHistory(string $equipmentCode, ?string $branch = null, int $page = 1, int $limit = 20): array;
}
