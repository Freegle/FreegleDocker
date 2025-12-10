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
        Schema::table('polls_users', function (Blueprint $table) {
            $table->foreign(['pollid'], 'polls_users_ibfk_1')->references(['id'])->on('polls')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'polls_users_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('polls_users', function (Blueprint $table) {
            $table->dropForeign('polls_users_ibfk_1');
            $table->dropForeign('polls_users_ibfk_2');
        });
    }
};
