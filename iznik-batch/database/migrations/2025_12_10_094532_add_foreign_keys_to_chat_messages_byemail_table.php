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
        Schema::table('chat_messages_byemail', function (Blueprint $table) {
            $table->foreign(['chatmsgid'], 'chat_messages_byemail_ibfk_1')->references(['id'])->on('chat_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'chat_messages_byemail_ibfk_2')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages_byemail', function (Blueprint $table) {
            $table->dropForeign('chat_messages_byemail_ibfk_1');
            $table->dropForeign('chat_messages_byemail_ibfk_2');
        });
    }
};
