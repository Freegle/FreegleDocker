<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_memberships_history_table.php */
class MembershipHistory extends Model
{
    protected $table = 'memberships_history';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'processingrequired' => 'boolean',
    ];
}
