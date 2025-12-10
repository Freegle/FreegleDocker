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
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreign(['groupid'], 'vouchers_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['userid'], 'vouchers_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign('vouchers_ibfk_1');
            $table->dropForeign('vouchers_ibfk_2');
        });
    }
};
