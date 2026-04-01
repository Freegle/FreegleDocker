<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_messages_history_table.php */
class MessageHistory extends Model
{
    protected $table = 'messages_history';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'arrival' => 'datetime',
        'repost' => 'boolean',
    ];
}
