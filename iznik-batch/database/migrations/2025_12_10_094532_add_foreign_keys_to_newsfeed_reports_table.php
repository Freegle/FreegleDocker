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
        Schema::table('newsfeed_reports', function (Blueprint $table) {
            $table->foreign(['newsfeedid'], 'newsfeed_reports_ibfk_1')->references(['id'])->on('newsfeed')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'newsfeed_reports_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsfeed_reports', function (Blueprint $table) {
            $table->dropForeign('newsfeed_reports_ibfk_1');
            $table->dropForeign('newsfeed_reports_ibfk_2');
        });
    }
};
