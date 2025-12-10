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
        Schema::table('users_notifications', function (Blueprint $table) {
            $table->foreign(['fromuser'], 'users_notifications_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['newsfeedid'], 'users_notifications_ibfk_2')->references(['id'])->on('newsfeed')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['touser'], 'users_notifications_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_notifications', function (Blueprint $table) {
            $table->dropForeign('users_notifications_ibfk_1');
            $table->dropForeign('users_notifications_ibfk_2');
            $table->dropForeign('users_notifications_ibfk_3');
        });
    }
};
