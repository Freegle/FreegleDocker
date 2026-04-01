<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_images_table.php */
class UserImage extends Model
{
    protected $table = 'users_images';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'default' => 'boolean',
        'archived' => 'boolean',
    ];
}
