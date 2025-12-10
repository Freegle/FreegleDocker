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
        Schema::table('giftaid', function (Blueprint $table) {
            $table->foreign(['userid'], 'giftaid_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('giftaid', function (Blueprint $table) {
            $table->dropForeign('giftaid_ibfk_1');
        });
    }
};
