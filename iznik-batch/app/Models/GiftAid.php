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
     * Get first name: use dedicated firstname column if set, else split fullname on first space.
     */
    public function getFirstname(): string
    {
        if ($this->firstname !== null && $this->firstname !== '') {
            return $this->firstname;
        }

        $spacePos = strpos($this->fullname ?? '', ' ');
        if ($spacePos === false) {
            return $this->fullname ?? '';
        }

        return substr($this->fullname, 0, $spacePos);
    }

    /**
     * Get last name: use dedicated lastname column if set, else split fullname on first space.
     * Returns empty string if fullname has no space and lastname is not set.
     */
    public function getLastname(): string
    {
        if ($this->lastname !== null && $this->lastname !== '') {
            return $this->lastname;
        }

        $spacePos = strpos($this->fullname ?? '', ' ');
        if ($spacePos === false) {
            return '';
        }

        return substr($this->fullname, $spacePos + 1);
    }

    /**
     * Check if the record has enough name information to produce a valid first and last name.
     * Either firstname+lastname must both be set, or fullname must contain a space.
     */
    public function hasValidNameSplit(): bool
    {
        if ($this->firstname !== null && $this->firstname !== '' &&
            $this->lastname !== null && $this->lastname !== '') {
            return true;
        }

        return strpos($this->fullname ?? '', ' ') !== false;
    }

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
