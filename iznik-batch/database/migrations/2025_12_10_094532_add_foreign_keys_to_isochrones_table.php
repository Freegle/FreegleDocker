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
        Schema::table('isochrones', function (Blueprint $table) {
            $table->foreign(['locationid'], 'isochrones_ibfk_2')->references(['id'])->on('locations')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('isochrones', function (Blueprint $table) {
            $table->dropForeign('isochrones_ibfk_2');
        });
    }
};
