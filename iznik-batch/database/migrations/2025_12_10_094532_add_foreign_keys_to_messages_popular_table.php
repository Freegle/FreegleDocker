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
        Schema::table('messages_popular', function (Blueprint $table) {
            $table->foreign(['msgid'], 'messages_popular_ibfk_1')->references(['id'])->on('messages')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['groupid'], 'messages_popular_ibfk_2')->references(['id'])->on('groups')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_popular', function (Blueprint $table) {
            $table->dropForeign('messages_popular_ibfk_1');
            $table->dropForeign('messages_popular_ibfk_2');
        });
    }
};
