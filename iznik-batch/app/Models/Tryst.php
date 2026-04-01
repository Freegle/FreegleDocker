<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_trysts_table.php */
class Tryst extends Model
{
    protected $table = 'trysts';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'arrangedat' => 'datetime',
        'arrangedfor' => 'datetime',
        'icssent' => 'boolean',
        'remindersent' => 'datetime',
        'user1confirmed' => 'datetime',
        'user2confirmed' => 'datetime',
        'user1declined' => 'datetime',
        'user2declined' => 'datetime',
    ];
}
