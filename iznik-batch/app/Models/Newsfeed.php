<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_newsfeed_table.php */
class Newsfeed extends Model
{
    protected $table = 'newsfeed';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'added' => 'datetime',
        'reviewrequired' => 'boolean',
        'deleted' => 'datetime',
        'hidden' => 'datetime',
        'closed' => 'boolean',
        'pinned' => 'boolean',
    ];
}
