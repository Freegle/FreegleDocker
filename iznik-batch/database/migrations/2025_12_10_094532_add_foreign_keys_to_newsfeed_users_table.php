<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('newsfeed_users', function (Blueprint $table) {
            $table->foreign(['userid'], 'newsfeed_users_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsfeed_users', function (Blueprint $table) {
            $table->dropForeign('newsfeed_users_ibfk_1');
        });
    }
};
