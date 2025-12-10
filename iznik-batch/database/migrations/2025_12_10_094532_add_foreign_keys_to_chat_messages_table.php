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
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreign(['chatid'], '_chat_messages_ibfk_1')->references(['id'])->on('chat_rooms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], '_chat_messages_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['refmsgid'], '_chat_messages_ibfk_3')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['reviewedby'], '_chat_messages_ibfk_4')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['refchatid'], '_chat_messages_ibfk_5')->references(['id'])->on('chat_rooms')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['imageid'], 'chat_messages_ibfk_1')->references(['id'])->on('chat_images')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign('_chat_messages_ibfk_1');
            $table->dropForeign('_chat_messages_ibfk_2');
            $table->dropForeign('_chat_messages_ibfk_3');
            $table->dropForeign('_chat_messages_ibfk_4');
            $table->dropForeign('_chat_messages_ibfk_5');
            $table->dropForeign('chat_messages_ibfk_1');
        });
    }
};
