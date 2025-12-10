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
        Schema::table('messages_history', function (Blueprint $table) {
            $table->foreign(['fromuser'], '_messages_history_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['groupid'], '_messages_history_ibfk_2')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_history', function (Blueprint $table) {
            $table->dropForeign('_messages_history_ibfk_1');
            $table->dropForeign('_messages_history_ibfk_2');
        });
    }
};
