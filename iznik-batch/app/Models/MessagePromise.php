<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_messages_promises_table.php */
class MessagePromise extends Model
{
    protected $table = 'messages_promises';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'promisedat' => 'datetime',
    ];
}
