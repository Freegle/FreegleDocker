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
        Schema::table('chat_roster', function (Blueprint $table) {
            $table->foreign(['chatid'], 'chat_roster_ibfk_1')->references(['id'])->on('chat_rooms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'chat_roster_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_roster', function (Blueprint $table) {
            $table->dropForeign('chat_roster_ibfk_1');
            $table->dropForeign('chat_roster_ibfk_2');
        });
    }
};
