<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AmpWriteToken extends Model
{
    protected $table = 'amp_write_tokens';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user this token belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the chat this token is for.
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chat_id');
    }

    /**
     * Get the email tracking record.
     */
    public function emailTracking(): BelongsTo
    {
        return $this->belongsTo(EmailTracking::class, 'email_tracking_id');
    }

    /**
     * Check if the token has been used.
     */
    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Check if the token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the token is valid (not used and not expired).
     */
    public function isValid(): bool
    {
        return !$this->isUsed() && !$this->isExpired();
    }

    /**
     * Create a new write token for a chat.
     */
    public static function createForChat(
        int $userId,
        int $chatId,
        ?int $emailTrackingId = null,
        ?int $expiryHours = null
    ): self {
        $expiryHours = $expiryHours ?? (int) config('freegle.amp.write_token_expiry_hours', 168);

        return self::create([
            'nonce' => Str::random(48),
            'user_id' => $userId,
            'chat_id' => $chatId,
            'email_tracking_id' => $emailTrackingId,
            'expires_at' => now()->addHours($expiryHours),
            'created_at' => now(),
        ]);
    }

    /**
     * Clean up expired tokens.
     */
    public static function cleanupExpired(): int
    {
        return self::where('expires_at', '<', now()->subDays(7))->delete();
    }
}
