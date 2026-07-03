<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GeoFence;
use App\Models\User;

class GeoFencePolicy
{
    public function view(User $user, GeoFence $geoFence): bool
    {
        return $user->id === $geoFence->user_id;
    }

    public function update(User $user, GeoFence $geoFence): bool
    {
        return $user->id === $geoFence->user_id;
    }

    public function delete(User $user, GeoFence $geoFence): bool
    {
        return $user->id === $geoFence->user_id;
    }
}
