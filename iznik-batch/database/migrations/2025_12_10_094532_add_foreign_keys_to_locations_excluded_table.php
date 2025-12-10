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
        Schema::table('locations_excluded', function (Blueprint $table) {
            $table->foreign(['locationid'], '_locations_excluded_ibfk_1')->references(['id'])->on('locations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'locations_excluded_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['groupid'], 'locations_excluded_ibfk_4')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations_excluded', function (Blueprint $table) {
            $table->dropForeign('_locations_excluded_ibfk_1');
            $table->dropForeign('locations_excluded_ibfk_3');
            $table->dropForeign('locations_excluded_ibfk_4');
        });
    }
};
