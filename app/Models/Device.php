<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Device extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'device_id',
        'token_hash',
        'type',
        'is_online',
        'last_seen_at',
        'meta',
    ];

    protected $casts = [
        'is_online'    => 'boolean',
        'last_seen_at' => 'datetime',
        'meta'         => 'array',
    ];

    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function captures(): HasMany
    {
        return $this->hasMany(DeviceCapture::class);
    }

    /**
     * Verify a plain-text token against the stored hash.
     */
    public function verifyToken(string $token): bool
    {
        return Hash::check($token, $this->token_hash);
    }

    /**
     * Generate a new token for this device.
     * Returns the plain-text token (only shown once).
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->update(['token_hash' => Hash::make($token)]);
        return $token;
    }

    /**
     * The MQTT topic this device subscribes to for commands.
     */
    public function commandTopic(): string
    {
        return "wolf/{$this->device_id}/command";
    }

    /**
     * Mark device as online with optional metadata.
     */
    public function markOnline(array $meta = []): void
    {
        $this->update([
            'is_online'    => true,
            'last_seen_at' => now(),
            'meta'         => array_merge($this->meta ?? [], $meta),
        ]);
    }

    public function markOffline(): void
    {
        $this->update(['is_online' => false]);
    }
}
