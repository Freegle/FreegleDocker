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
        Schema::table('users_banned', function (Blueprint $table) {
            $table->foreign(['userid'], 'users_banned_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], 'users_banned_ibfk_2')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['byuser'], 'users_banned_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_banned', function (Blueprint $table) {
            $table->dropForeign('users_banned_ibfk_1');
            $table->dropForeign('users_banned_ibfk_2');
            $table->dropForeign('users_banned_ibfk_3');
        });
    }
};
