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
        Schema::table('communityevents_dates', function (Blueprint $table) {
            $table->foreign(['eventid'], 'communityevents_dates_ibfk_1')->references(['id'])->on('communityevents')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communityevents_dates', function (Blueprint $table) {
            $table->dropForeign('communityevents_dates_ibfk_1');
        });
    }
};
