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
        Schema::table('messages_isochrones', function (Blueprint $table) {
            $table->foreign(['msgid'], 'messages_isochrones_ibfk_1')->references(['id'])->on('messages')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['isochroneid'], 'messages_isochrones_ibfk_2')->references(['id'])->on('isochrones')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_isochrones', function (Blueprint $table) {
            $table->dropForeign('messages_isochrones_ibfk_1');
            $table->dropForeign('messages_isochrones_ibfk_2');
        });
    }
};
