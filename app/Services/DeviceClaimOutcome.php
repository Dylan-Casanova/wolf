<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceClaimResult;
use App\Models\Device;

final readonly class DeviceClaimOutcome
{
    public function __construct(
        public DeviceClaimResult $result,
        public ?Device $device = null,
    ) {}
}
