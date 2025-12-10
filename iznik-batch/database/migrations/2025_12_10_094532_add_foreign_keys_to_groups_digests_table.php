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
        Schema::table('groups_digests', function (Blueprint $table) {
            $table->foreign(['groupid'], 'groups_digests_ibfk_1')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'groups_digests_ibfk_3')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups_digests', function (Blueprint $table) {
            $table->dropForeign('groups_digests_ibfk_1');
            $table->dropForeign('groups_digests_ibfk_3');
        });
    }
};
