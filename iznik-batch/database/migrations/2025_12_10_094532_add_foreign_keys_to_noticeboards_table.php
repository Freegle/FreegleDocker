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
        Schema::table('noticeboards', function (Blueprint $table) {
            $table->foreign(['addedby'], 'noticeboards_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noticeboards', function (Blueprint $table) {
            $table->dropForeign('noticeboards_ibfk_1');
        });
    }
};
