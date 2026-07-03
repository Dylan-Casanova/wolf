<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceClaimResult
{
    case Claimed;
    case DeviceNotFound;
    case AlreadyOwned;
    case AlreadyClaimed;
}
