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
        Schema::table('shortlink_clicks', function (Blueprint $table) {
            $table->foreign(['shortlinkid'], 'shortlink_clicks_ibfk_1')->references(['id'])->on('shortlinks')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shortlink_clicks', function (Blueprint $table) {
            $table->dropForeign('shortlink_clicks_ibfk_1');
        });
    }
};
