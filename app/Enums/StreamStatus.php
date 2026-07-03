<?php

declare(strict_types=1);

namespace App\Enums;

enum StreamStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Ended = 'ended';
}
