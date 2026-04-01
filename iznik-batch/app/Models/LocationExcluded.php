<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_locations_excluded_table.php */
class LocationExcluded extends Model
{
    protected $table = 'locations_excluded';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'norfolk' => 'boolean',
    ];
}
