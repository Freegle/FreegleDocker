<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $userid
 * @property \Illuminate\Support\Carbon $timestamp
 * @property string $period
 * @property string $fullname
 * @property string $homeaddress
 * @property \Illuminate\Support\Carbon|null $deleted
 * @property \Illuminate\Support\Carbon|null $reviewed
 * @property \Illuminate\Support\Carbon $updated
 * @property string|null $postcode
 * @property string|null $housenameornumber
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereFullname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereHomeaddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereHousenameornumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid wherePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid wherePostcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereReviewed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereUpdated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GiftAid whereUserid($value)
 * @mixin \Eloquent
 */
class GiftAid extends Model
{
    protected $table = 'giftaid';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const PERIOD_THIS = 'This';
    public const PERIOD_SINCE = 'Since';
    public const PERIOD_FUTURE = 'Future';
    public const PERIOD_DECLINED = 'Declined';
    public const PERIOD_PAST4_YEARS_AND_FUTURE = 'Past4YearsAndFuture';

    protected $casts = [
        'timestamp' => 'datetime',
        'deleted' => 'datetime',
        'reviewed' => 'datetime',
        'updated' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Check if gift aid was declined.
     */
    public function isDeclined(): bool
    {
        return $this->period === self::PERIOD_DECLINED;
    }

    /**
     * Check if gift aid is active.
     */
    public function isActive(): bool
    {
        return $this->deleted === NULL && !$this->isDeclined();
    }
}
