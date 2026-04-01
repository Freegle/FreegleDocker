<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_messages_outcomes_intended_table.php */
class MessageOutcomeIntended extends Model
{
    protected $table = 'messages_outcomes_intended';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
