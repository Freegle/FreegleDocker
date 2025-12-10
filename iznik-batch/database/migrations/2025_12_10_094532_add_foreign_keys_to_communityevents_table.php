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
        Schema::table('communityevents', function (Blueprint $table) {
            $table->foreign(['userid'], 'communityevents_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['userid'], 'communityevents_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communityevents', function (Blueprint $table) {
            $table->dropForeign('communityevents_ibfk_1');
            $table->dropForeign('communityevents_ibfk_2');
        });
    }
};
