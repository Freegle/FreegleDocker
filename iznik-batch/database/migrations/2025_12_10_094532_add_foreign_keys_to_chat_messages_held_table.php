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
        Schema::table('chat_messages_held', function (Blueprint $table) {
            $table->foreign(['msgid'], 'chat_messages_held_ibfk_1')->references(['id'])->on('chat_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'chat_messages_held_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages_held', function (Blueprint $table) {
            $table->dropForeign('chat_messages_held_ibfk_1');
            $table->dropForeign('chat_messages_held_ibfk_2');
        });
    }
};
