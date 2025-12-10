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
        Schema::table('isochrones_users', function (Blueprint $table) {
            $table->foreign(['isochroneid'], 'isochrones_users_ibfk_1')->references(['id'])->on('isochrones')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['userid'], 'isochrones_users_ibfk_2')->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('isochrones_users', function (Blueprint $table) {
            $table->dropForeign('isochrones_users_ibfk_1');
            $table->dropForeign('isochrones_users_ibfk_2');
        });
    }
};
