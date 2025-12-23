<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Job extends Model
{
    protected $table = 'jobs';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'posted_at' => 'datetime',
        'seenat' => 'datetime',
        'cpc' => 'decimal:4',
    ];

    /**
     * Query jobs near a location.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $limit Maximum number of jobs to return
     * @param int $radiusMiles Search radius in miles
     */
    public static function nearLocation(float $lat, float $lng, int $limit = 4, int $radiusMiles = 50): Collection
    {
        // Convert miles to meters (1 mile = 1609.34 meters).
        $radiusMeters = $radiusMiles * 1609.34;

        return static::select('id', 'title', 'location', 'company', 'city', 'url')
            ->whereRaw(
                'ST_Distance_Sphere(geometry, ST_SRID(POINT(?, ?), 4326)) < ?',
                [$lng, $lat, $radiusMeters]
            )
            ->orderByRaw(
                'ST_Distance_Sphere(geometry, ST_SRID(POINT(?, ?), 4326))',
                [$lng, $lat]
            )
            ->limit($limit)
            ->get();
    }
}
