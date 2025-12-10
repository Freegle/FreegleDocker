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
        Schema::table('users_nudges', function (Blueprint $table) {
            $table->foreign(['fromuser'], 'users_nudges_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['touser'], 'users_nudges_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_nudges', function (Blueprint $table) {
            $table->dropForeign('users_nudges_ibfk_1');
            $table->dropForeign('users_nudges_ibfk_2');
        });
    }
};
