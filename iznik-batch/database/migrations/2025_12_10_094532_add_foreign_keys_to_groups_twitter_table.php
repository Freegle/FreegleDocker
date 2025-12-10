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
        Schema::table('groups_twitter', function (Blueprint $table) {
            $table->foreign(['groupid'], 'groups_twitter_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'groups_twitter_ibfk_2')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['eventid'], 'groups_twitter_ibfk_3')->references(['id'])->on('communityevents')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups_twitter', function (Blueprint $table) {
            $table->dropForeign('groups_twitter_ibfk_1');
            $table->dropForeign('groups_twitter_ibfk_2');
            $table->dropForeign('groups_twitter_ibfk_3');
        });
    }
};
