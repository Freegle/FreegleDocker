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
        Schema::table('teams_members', function (Blueprint $table) {
            $table->foreign(['userid'], 'teams_members_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['teamid'], 'teams_members_ibfk_2')->references(['id'])->on('teams')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams_members', function (Blueprint $table) {
            $table->dropForeign('teams_members_ibfk_1');
            $table->dropForeign('teams_members_ibfk_2');
        });
    }
};
