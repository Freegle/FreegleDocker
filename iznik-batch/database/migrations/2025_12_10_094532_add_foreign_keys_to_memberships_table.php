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
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreign(['groupid'], 'memberships_ibfk_2')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'memberships_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['heldby'], 'memberships_ibfk_4')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['configid'], 'memberships_ibfk_5')->references(['id'])->on('mod_configs')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign('memberships_ibfk_2');
            $table->dropForeign('memberships_ibfk_3');
            $table->dropForeign('memberships_ibfk_4');
            $table->dropForeign('memberships_ibfk_5');
        });
    }
};
