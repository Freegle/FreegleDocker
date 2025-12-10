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
        Schema::table('items_index', function (Blueprint $table) {
            $table->foreign(['itemid'], 'items_index_ibfk_1')->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['wordid'], 'items_index_ibfk_2')->references(['id'])->on('words')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items_index', function (Blueprint $table) {
            $table->dropForeign('items_index_ibfk_1');
            $table->dropForeign('items_index_ibfk_2');
        });
    }
};
