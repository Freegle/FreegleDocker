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
        Schema::table('users_dashboard', function (Blueprint $table) {
            $table->foreign(['userid'], 'users_dashboard_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], 'users_dashboard_ibfk_2')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_dashboard', function (Blueprint $table) {
            $table->dropForeign('users_dashboard_ibfk_1');
            $table->dropForeign('users_dashboard_ibfk_2');
        });
    }
};
