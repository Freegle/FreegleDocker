<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_ratings_table.php */
class Rating extends Model
{
    protected $table = 'ratings';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'visible' => 'boolean',
        'reviewrequired' => 'boolean',
    ];
}
