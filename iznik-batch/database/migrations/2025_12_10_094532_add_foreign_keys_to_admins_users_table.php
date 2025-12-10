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
        Schema::table('admins_users', function (Blueprint $table) {
            $table->foreign(['userid'], 'admins_users_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['adminid'], 'admins_users_ibfk_2')->references(['id'])->on('admins')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins_users', function (Blueprint $table) {
            $table->dropForeign('admins_users_ibfk_1');
            $table->dropForeign('admins_users_ibfk_2');
        });
    }
};
