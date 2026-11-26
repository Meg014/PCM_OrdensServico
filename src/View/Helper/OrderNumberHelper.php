<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Service\WorkOrderStatusResolver;
use Cake\View\Helper;

final class OrderNumberHelper extends Helper
{
    public function colorClass(?string $situation): string
    {
        return (new WorkOrderStatusResolver())->resolve($situation, null) === WorkOrderStatusResolver::CANCELLED
            ? 'text-danger-emphasis' : '';
    }
}
