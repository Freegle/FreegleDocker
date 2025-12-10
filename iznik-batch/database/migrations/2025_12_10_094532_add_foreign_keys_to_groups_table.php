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
        Schema::table('groups', function (Blueprint $table) {
            $table->foreign(['profile'], 'groups_ibfk_1')->references(['id'])->on('groups_images')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['cover'], 'groups_ibfk_2')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['defaultlocation'], 'groups_ibfk_4')->references(['id'])->on('locations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['affiliationconfirmedby'], 'groups_ibfk_5')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign('groups_ibfk_1');
            $table->dropForeign('groups_ibfk_2');
            $table->dropForeign('groups_ibfk_4');
            $table->dropForeign('groups_ibfk_5');
        });
    }
};
