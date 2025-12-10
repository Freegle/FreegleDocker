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
        Schema::table('newsfeed', function (Blueprint $table) {
            $table->foreign(['userid'], 'newsfeed_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'newsfeed_ibfk_2')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], 'newsfeed_ibfk_3')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['eventid'], 'newsfeed_ibfk_4')->references(['id'])->on('communityevents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['volunteeringid'], 'newsfeed_ibfk_5')->references(['id'])->on('volunteering')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['publicityid'], 'newsfeed_ibfk_6')->references(['id'])->on('groups_facebook_toshare')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['storyid'], 'newsfeed_ibfk_7')->references(['id'])->on('users_stories')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsfeed', function (Blueprint $table) {
            $table->dropForeign('newsfeed_ibfk_1');
            $table->dropForeign('newsfeed_ibfk_2');
            $table->dropForeign('newsfeed_ibfk_3');
            $table->dropForeign('newsfeed_ibfk_4');
            $table->dropForeign('newsfeed_ibfk_5');
            $table->dropForeign('newsfeed_ibfk_6');
            $table->dropForeign('newsfeed_ibfk_7');
        });
    }
};
