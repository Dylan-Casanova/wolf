<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeviceClaimResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Services\DeviceClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceClaimController extends Controller
{
    public function __construct(private DeviceClaimService $service) {}

    public function store(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string'],
        ]);

        $outcome = $this->service->claim($request->user(), $validated['device_id']);

        return match ($outcome->result) {
            DeviceClaimResult::Claimed => DeviceResource::make($outcome->device)
                ->response()
                ->setStatusCode(201),
            DeviceClaimResult::DeviceNotFound => response()->json(
                ['message' => 'Device not found.'],
                404,
            ),
            DeviceClaimResult::AlreadyOwned => response()->json(
                ['message' => 'You already own this device.'],
                422,
            ),
            DeviceClaimResult::AlreadyClaimed => response()->json(
                ['message' => 'Device is already claimed.'],
                422,
            ),
        };
    }
}
