<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEmail extends Model
{
    protected $table = 'users_emails';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'added' => 'datetime',
        'validated' => 'datetime',
        'preferred' => 'boolean',
        'bounced' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Scope to validated emails.
     */
    public function scopeValidated(Builder $query): Builder
    {
        return $query->whereNotNull('validated');
    }

    /**
     * Scope to unvalidated emails.
     */
    public function scopeUnvalidated(Builder $query): Builder
    {
        return $query->whereNull('validated');
    }

    /**
     * Scope to preferred emails.
     */
    public function scopePreferred(Builder $query): Builder
    {
        return $query->where('preferred', 1);
    }

    /**
     * Scope to non-bouncing emails.
     */
    public function scopeNotBounced(Builder $query): Builder
    {
        return $query->whereNull('bounced');
    }

    /**
     * Check if this email is validated.
     */
    public function isValidated(): bool
    {
        return $this->validated !== NULL;
    }

    /**
     * Get the domain part of the email.
     */
    public function getDomain(): string
    {
        $parts = explode('@', $this->email);
        return $parts[1] ?? '';
    }
}
