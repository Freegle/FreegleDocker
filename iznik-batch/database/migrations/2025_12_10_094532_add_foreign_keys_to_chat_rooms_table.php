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
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->foreign(['groupid'], 'chat_rooms_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user1'], 'chat_rooms_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user2'], 'chat_rooms_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropForeign('chat_rooms_ibfk_1');
            $table->dropForeign('chat_rooms_ibfk_2');
            $table->dropForeign('chat_rooms_ibfk_3');
        });
    }
};
