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
        Schema::table('messages_by', function (Blueprint $table) {
            $table->foreign(['msgid'], 'messages_by_ibfk_1')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'messages_by_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_by', function (Blueprint $table) {
            $table->dropForeign('messages_by_ibfk_1');
            $table->dropForeign('messages_by_ibfk_2');
        });
    }
};
