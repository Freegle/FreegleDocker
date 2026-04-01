<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_isochrones_users_table.php */
class IsochroneUser extends Model
{
    protected $table = 'isochrones_users';
    protected $guarded = ['id'];
    public $timestamps = false;
}
