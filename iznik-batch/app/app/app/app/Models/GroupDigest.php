<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupDigest extends Model
{
    protected $table = 'groups_digests';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'msgdate' => 'datetime',
        'started' => 'datetime',
        'ended' => 'datetime',
    ];

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get the last message sent.
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'msgid');
    }
}
