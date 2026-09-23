<?php
declare(strict_types=1);

namespace App\Service\Protheus\Presentation;

enum Availability: string
{
    case Disabled = 'disabled';
    case Unavailable = 'unavailable';
    case NotFound = 'not_found';
    case Available = 'available';
}
