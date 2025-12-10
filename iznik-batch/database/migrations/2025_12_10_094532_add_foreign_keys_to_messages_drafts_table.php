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
        Schema::table('messages_drafts', function (Blueprint $table) {
            $table->foreign(['userid'], 'messages_drafts_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'messages_drafts_ibfk_2')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], 'messages_drafts_ibfk_3')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_drafts', function (Blueprint $table) {
            $table->dropForeign('messages_drafts_ibfk_1');
            $table->dropForeign('messages_drafts_ibfk_2');
            $table->dropForeign('messages_drafts_ibfk_3');
        });
    }
};
