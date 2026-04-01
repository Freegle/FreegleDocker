<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_teams_members_table.php */
class TeamMember extends Model
{
    protected $table = 'teams_members';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
    ];
}
