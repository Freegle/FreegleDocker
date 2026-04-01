<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_communityevents_table.php */
class CommunityEvent extends Model
{
    protected $table = 'communityevents';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'pending' => 'boolean',
        'added' => 'datetime',
        'deleted' => 'boolean',
        'deletedcovid' => 'boolean',
    ];
}
