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
        Schema::table('groups_facebook_shares', function (Blueprint $table) {
            $table->foreign(['groupid'], 'groups_facebook_shares_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['uid'], 'groups_facebook_shares_ibfk_2')->references(['uid'])->on('groups_facebook')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups_facebook_shares', function (Blueprint $table) {
            $table->dropForeign('groups_facebook_shares_ibfk_1');
            $table->dropForeign('groups_facebook_shares_ibfk_2');
        });
    }
};
