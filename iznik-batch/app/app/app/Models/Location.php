<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $table = 'locations';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'osm_place' => 'boolean',
        'osm_amenity' => 'boolean',
        'osm_shop' => 'boolean',
    ];
}
