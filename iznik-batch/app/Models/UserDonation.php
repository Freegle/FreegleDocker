<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDonation extends Model
{
    protected $table = 'users_donations';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const SOURCE_DONATE_WITH_PAYPAL = 'DonateWithPayPal';
    public const SOURCE_PAYPAL_GIVING_FUND = 'PayPalGivingFund';
    public const SOURCE_FACEBOOK = 'Facebook';
    public const SOURCE_EBAY = 'eBay';
    public const SOURCE_BANK_TRANSFER = 'BankTransfer';
    public const SOURCE_STRIPE = 'Stripe';

    protected $casts = [
        'timestamp' => 'datetime',
        'amount' => 'decimal:2',
        'giftaidconsent' => 'boolean',
        'giftaidchaseup' => 'datetime',
        'thanked' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Scope to donations from PayPal or Stripe.
     */
    public function scopeFromPaymentProviders(Builder $query): Builder
    {
        return $query->whereIn('source', [self::SOURCE_DONATE_WITH_PAYPAL, self::SOURCE_STRIPE]);
    }

    /**
     * Scope to donations without gift aid consent.
     */
    public function scopeWithoutGiftAidConsent(Builder $query): Builder
    {
        return $query->where('giftaidconsent', 0);
    }

    /**
     * Scope to donations not yet chased for gift aid.
     */
    public function scopeNotChasedForGiftAid(Builder $query): Builder
    {
        return $query->whereNull('giftaidchaseup');
    }

    /**
     * Scope to donations not yet thanked.
     */
    public function scopeNotThanked(Builder $query): Builder
    {
        return $query->whereNull('thanked');
    }

    /**
     * Scope to recent donations.
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('timestamp', '>=', now()->subDays($days));
    }

    /**
     * Scope to donations in a date range.
     */
    public function scopeInDateRange(Builder $query, int $minDays, int $maxDays): Builder
    {
        return $query->where('timestamp', '>=', now()->subDays($maxDays))
            ->where('timestamp', '<=', now()->subDays($minDays));
    }

    /**
     * Check if this donation can be chased for gift aid.
     */
    public function canChaseGiftAid(): bool
    {
        return !$this->giftaidconsent
            && $this->giftaidchaseup === null
            && in_array($this->source, [self::SOURCE_DONATE_WITH_PAYPAL, self::SOURCE_STRIPE]);
    }
}
