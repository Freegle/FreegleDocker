<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailQueueItem extends Model
{
    protected $table = 'email_queue';

    public $timestamps = FALSE;

    protected $fillable = [
        'email_type',
        'user_id',
        'group_id',
        'message_id',
        'chat_id',
        'extra_data',
        'processed_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /**
     * Scope for pending items (not yet processed or failed).
     */
    public function scopePending($query)
    {
        return $query->whereNull('processed_at')->whereNull('failed_at');
    }
}
