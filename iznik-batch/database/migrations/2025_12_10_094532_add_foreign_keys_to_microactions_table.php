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
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['searchterm1'])->references(['id'])->on('search_terms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['searchterm2'])->references(['id'])->on('search_terms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['item1'])->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['item2'])->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['facebook_post'])->references(['id'])->on('groups_facebook_toshare')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['rotatedimage'])->references(['id'])->on('messages_attachments')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('microactions', function (Blueprint $table) {
        });
    }
};
