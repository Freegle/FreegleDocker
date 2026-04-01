<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_volunteering_table.php */
class Volunteering extends Model
{
    protected $table = 'volunteering';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'pending' => 'boolean',
        'online' => 'boolean',
        'added' => 'datetime',
        'deleted' => 'boolean',
        'askedtorenew' => 'datetime',
        'renewed' => 'datetime',
        'expired' => 'boolean',
        'deletedcovid' => 'boolean',
    ];
}
