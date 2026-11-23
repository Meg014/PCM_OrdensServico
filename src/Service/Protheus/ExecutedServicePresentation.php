<?php
declare(strict_types=1);

namespace App\Service\Protheus;

/** Summary only: never rewrites STQ execution notes or historical entry descriptions. */
final class ExecutedServicePresentation
{
    public const CHECKLIST = 'CHECKLIST EXECUTADO';

    public static function display(?string $original, array $order): string
    {
        return RouteChecklist::isOrder($order) ? self::CHECKLIST : OrderDescriptions::display($original);
    }
}
