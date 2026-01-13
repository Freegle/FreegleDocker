<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailTracking extends Model
{
    protected $table = 'email_tracking';

    protected static function boot()
    {
        parent::boot();

        // Auto-generate tracking_id if not provided.
        static::creating(function ($model) {
            if (empty($model->tracking_id)) {
                $model->tracking_id = self::generateTrackingId();
            }
        });
    }

    protected $fillable = [
        'tracking_id',
        'email_type',
        'userid',
        'groupid',
        'recipient_email',
        'subject',
        'metadata',
        'sent_at',
        'delivered_at',
        'bounced_at',
        'opened_at',
        'clicked_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'bounced_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Generate a unique tracking ID
     */
    public static function generateTrackingId(): string
    {
        return Str::random(32);
    }

    /**
     * Create a new tracking record for an email
     */
    public static function createForEmail(
        string $emailType,
        string $recipientEmail,
        ?int $userId = null,
        ?int $groupId = null,
        ?string $subject = null,
        ?array $metadata = null
    ): self {
        // Validate that user exists to avoid foreign key constraint failures.
        // If user doesn't exist, set userId to null.
        if ($userId !== null && !User::where('id', $userId)->exists()) {
            $userId = null;
        }

        // Similarly validate group exists.
        if ($groupId !== null && !Group::where('id', $groupId)->exists()) {
            $groupId = null;
        }

        return self::create([
            'tracking_id' => self::generateTrackingId(),
            'email_type' => $emailType,
            'userid' => $userId,
            'groupid' => $groupId,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'metadata' => $metadata,
            'sent_at' => now(),
        ]);
    }

    /**
     * Get the user this email was sent to
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Get the group associated with this email
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get click events for this email
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(EmailTrackingClick::class, 'email_tracking_id');
    }

    /**
     * Get image load events for this email
     */
    public function images(): HasMany
    {
        return $this->hasMany(EmailTrackingImage::class, 'email_tracking_id');
    }

    /**
     * Check if email was opened
     */
    public function wasOpened(): bool
    {
        return $this->opened_at !== null;
    }

    /**
     * Check if any link was clicked
     */
    public function wasClicked(): bool
    {
        return $this->clicked_at !== null;
    }

    /**
     * Check if user unsubscribed via this email
     */
    public function didUnsubscribe(): bool
    {
        return $this->unsubscribed_at !== null;
    }

    /**
     * Get the tracking pixel URL
     */
    public function getPixelUrl(): string
    {
        // Email tracking routes are at /e/d/... (not /apiv2/e/d/...)
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        return "{$baseUrl}/e/d/p/{$this->tracking_id}";
    }

    /**
     * Get a tracked link URL
     */
    public function getTrackedLinkUrl(string $destinationUrl, ?string $position = null, ?string $action = null): string
    {
        // Email tracking routes are at /e/d/... (not /apiv2/e/d/...)
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        $encodedUrl = base64_encode($destinationUrl);
        $url = "{$baseUrl}/e/d/r/{$this->tracking_id}?url={$encodedUrl}";

        if ($position) {
            $url .= "&p=" . urlencode($position);
        }
        if ($action) {
            $url .= "&a=" . urlencode($action);
        }

        return $url;
    }

    /**
     * Get a tracked image URL
     */
    public function getTrackedImageUrl(string $originalImageUrl, string $position, ?int $scrollPercent = null): string
    {
        // Email tracking routes are at /e/d/... (not /apiv2/e/d/...)
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        $encodedUrl = base64_encode($originalImageUrl);
        $url = "{$baseUrl}/e/d/i/{$this->tracking_id}?url={$encodedUrl}&p=" . urlencode($position);

        if ($scrollPercent !== null) {
            $url .= "&s={$scrollPercent}";
        }

        return $url;
    }
}
