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
        Schema::table('messages_items', function (Blueprint $table) {
            $table->foreign(['msgid'], 'messages_items_ibfk_1')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['itemid'], 'messages_items_ibfk_2')->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_items', function (Blueprint $table) {
            $table->dropForeign('messages_items_ibfk_1');
            $table->dropForeign('messages_items_ibfk_2');
        });
    }
};
