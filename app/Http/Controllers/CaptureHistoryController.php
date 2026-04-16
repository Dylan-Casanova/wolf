<?php

namespace App\Http\Controllers;

use App\Http\Resources\CaptureResource;
use App\Models\DeviceCapture;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CaptureHistoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DeviceCapture::with(['device', 'user'])
            ->latest();

        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
        }

        $captures = CaptureResource::collection($query->paginate(20));

        return Inertia::render('Captures/History', [
            'captures' => $captures,
            'isAdmin'  => $user->is_admin,
        ]);
    }
}
