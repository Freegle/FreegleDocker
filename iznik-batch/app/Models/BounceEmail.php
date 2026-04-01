<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_bounces_emails_table.php */
class BounceEmail extends Model
{
    protected $table = 'bounces_emails';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'permanent' => 'boolean',
        'reset' => 'boolean',
    ];
}
