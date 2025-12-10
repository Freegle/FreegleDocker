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
        Schema::table('noticeboards_checks', function (Blueprint $table) {
            $table->foreign(['userid'], 'noticeboards_checks_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['noticeboardid'], 'noticeboards_checks_ibfk_2')->references(['id'])->on('noticeboards')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noticeboards_checks', function (Blueprint $table) {
            $table->dropForeign('noticeboards_checks_ibfk_1');
            $table->dropForeign('noticeboards_checks_ibfk_2');
        });
    }
};
