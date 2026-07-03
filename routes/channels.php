<?php

declare(strict_types=1);

use App\Models\Stream;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('devices', function ($user) {
    return $user->is_admin;
});

Broadcast::channel('stream.{streamId}', function ($user, $streamId) {
    $stream = Stream::find($streamId);

    return $stream && (int) $user->id === (int) $stream->user_id;
});

Broadcast::channel('device.{deviceId}', function ($user, $deviceId) {
    return $user->devices()->where('id', $deviceId)->exists();
});
