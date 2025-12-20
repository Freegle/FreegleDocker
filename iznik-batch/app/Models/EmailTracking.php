<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailTracking extends Model
{
    protected $table = 'email_tracking';

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
        $domain = config('app.api_domain', 'apiv2.ilovefreegle.org');
        return "https://{$domain}/e/d/p/{$this->tracking_id}";
    }

    /**
     * Get a tracked link URL
     */
    public function getTrackedLinkUrl(string $destinationUrl, ?string $position = null, ?string $action = null): string
    {
        $domain = config('app.api_domain', 'apiv2.ilovefreegle.org');
        $encodedUrl = base64_encode($destinationUrl);
        $url = "https://{$domain}/e/d/r/{$this->tracking_id}?url={$encodedUrl}";

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
        $domain = config('app.api_domain', 'apiv2.ilovefreegle.org');
        $encodedUrl = base64_encode($originalImageUrl);
        $url = "https://{$domain}/e/d/i/{$this->tracking_id}?url={$encodedUrl}&p=" . urlencode($position);

        if ($scrollPercent !== null) {
            $url .= "&s={$scrollPercent}";
        }

        return $url;
    }
}
