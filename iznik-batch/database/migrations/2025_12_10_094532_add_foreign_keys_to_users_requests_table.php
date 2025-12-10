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
        Schema::table('users_requests', function (Blueprint $table) {
            $table->foreign(['userid'], 'users_requests_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['addressid'], 'users_requests_ibfk_2')->references(['id'])->on('users_addresses')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['completedby'], 'users_requests_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_requests', function (Blueprint $table) {
            $table->dropForeign('users_requests_ibfk_1');
            $table->dropForeign('users_requests_ibfk_2');
            $table->dropForeign('users_requests_ibfk_3');
        });
    }
};
