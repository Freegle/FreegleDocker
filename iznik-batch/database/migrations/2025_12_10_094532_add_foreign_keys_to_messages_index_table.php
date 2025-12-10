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
        Schema::table('messages_index', function (Blueprint $table) {
            $table->foreign(['msgid'], '_messages_index_ibfk_1')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], '_messages_index_ibfk_3')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['wordid'], 'messages_index_ibfk_1')->references(['id'])->on('words')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_index', function (Blueprint $table) {
            $table->dropForeign('_messages_index_ibfk_1');
            $table->dropForeign('_messages_index_ibfk_3');
            $table->dropForeign('messages_index_ibfk_1');
        });
    }
};
