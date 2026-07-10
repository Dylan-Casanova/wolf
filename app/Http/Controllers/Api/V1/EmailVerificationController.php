<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Resend the email verification link for the authenticated user.
     * Mirrors the web `verification.send` route but returns JSON so the
     * iOS client can render its own confirmation UI.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['status' => 'already-verified'], 200);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['status' => 'sent'], 202);
    }
}
