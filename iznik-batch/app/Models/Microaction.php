<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_microactions_table.php */
class Microaction extends Model
{
    protected $table = 'microactions';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'score_positive' => 'float',
        'score_negative' => 'float',
    ];
}
