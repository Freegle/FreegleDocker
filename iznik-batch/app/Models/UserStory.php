<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @see ../../database/migrations/2025_12_10_094529_create_users_stories_table.php */
class UserStory extends Model
{
    protected $table = 'users_stories';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'public' => 'boolean',
        'reviewed' => 'boolean',
        'tweeted' => 'boolean',
        'mailedtocentral' => 'boolean',
        'mailedtomembers' => 'boolean',
        'newsletterreviewed' => 'boolean',
        'newsletter' => 'boolean',
        'updated' => 'datetime',
        'fromnewsfeed' => 'boolean',
    ];
}
