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
        Schema::table('microactions', function (Blueprint $table) {
            $table->foreign(['userid'], 'microactions_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'microactions_ibfk_2')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['searchterm1'], 'microactions_ibfk_3')->references(['id'])->on('search_terms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['searchterm2'], 'microactions_ibfk_4')->references(['id'])->on('search_terms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['item1'], 'microactions_ibfk_5')->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['item2'], 'microactions_ibfk_6')->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['facebook_post'], 'microactions_ibfk_7')->references(['id'])->on('groups_facebook_toshare')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['rotatedimage'], 'microactions_ibfk_8')->references(['id'])->on('messages_attachments')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('microactions', function (Blueprint $table) {
            $table->dropForeign('microactions_ibfk_1');
            $table->dropForeign('microactions_ibfk_2');
            $table->dropForeign('microactions_ibfk_3');
            $table->dropForeign('microactions_ibfk_4');
            $table->dropForeign('microactions_ibfk_5');
            $table->dropForeign('microactions_ibfk_6');
            $table->dropForeign('microactions_ibfk_7');
            $table->dropForeign('microactions_ibfk_8');
        });
    }
};
