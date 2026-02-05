<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
