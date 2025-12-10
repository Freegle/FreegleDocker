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
        Schema::table('users_chatlists_index', function (Blueprint $table) {
            $table->foreign(['chatlistid'], 'users_chatlists_index_ibfk_1')->references(['id'])->on('users_chatlists')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'users_chatlists_index_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['chatid'], 'users_chatlists_index_ibfk_3')->references(['id'])->on('chat_rooms')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_chatlists_index', function (Blueprint $table) {
            $table->dropForeign('users_chatlists_index_ibfk_1');
            $table->dropForeign('users_chatlists_index_ibfk_2');
            $table->dropForeign('users_chatlists_index_ibfk_3');
        });
    }
};
