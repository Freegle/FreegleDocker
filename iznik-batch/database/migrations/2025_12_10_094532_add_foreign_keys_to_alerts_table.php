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
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreign(['groupid'], 'alerts_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['createdby'], 'alerts_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropForeign('alerts_ibfk_1');
            $table->dropForeign('alerts_ibfk_2');
        });
    }
};
