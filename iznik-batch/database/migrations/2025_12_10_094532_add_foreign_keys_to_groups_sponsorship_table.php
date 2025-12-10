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
        Schema::table('groups_sponsorship', function (Blueprint $table) {
            $table->foreign(['groupid'], 'groups_sponsorship_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups_sponsorship', function (Blueprint $table) {
            $table->dropForeign('groups_sponsorship_ibfk_1');
        });
    }
};
