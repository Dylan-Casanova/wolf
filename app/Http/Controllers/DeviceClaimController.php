<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeviceClaimResult;
use App\Services\DeviceClaimService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceClaimController extends Controller
{
    public function __construct(private DeviceClaimService $service) {}

    public function create()
    {
        return Inertia::render('Devices/Claim');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string'],
        ]);

        $result = $this->service->claim($request->user(), $validated['device_id']);

        return match ($result) {
            DeviceClaimResult::Claimed => redirect()->route('dashboard'),
            DeviceClaimResult::DeviceNotFound => back()->withErrors(['device_id' => 'Device not found.']),
            DeviceClaimResult::AlreadyOwned => back()->withErrors(['device_id' => 'You already own this device.']),
            DeviceClaimResult::AlreadyClaimed => back()->withErrors(['device_id' => 'Device is already claimed.']),
        };
    }
}
