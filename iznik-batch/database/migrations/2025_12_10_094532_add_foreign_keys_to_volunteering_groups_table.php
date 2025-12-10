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
        Schema::table('volunteering_groups', function (Blueprint $table) {
            $table->foreign(['volunteeringid'], 'volunteering_groups_ibfk_1')->references(['id'])->on('volunteering')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], 'volunteering_groups_ibfk_2')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('volunteering_groups', function (Blueprint $table) {
            $table->dropForeign('volunteering_groups_ibfk_1');
            $table->dropForeign('volunteering_groups_ibfk_2');
        });
    }
};
