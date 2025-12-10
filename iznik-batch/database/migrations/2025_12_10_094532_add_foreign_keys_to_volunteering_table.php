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
        Schema::table('volunteering', function (Blueprint $table) {
            $table->foreign(['deletedby'], 'volunteering_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['heldby'], 'volunteering_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('volunteering', function (Blueprint $table) {
            $table->dropForeign('volunteering_ibfk_1');
            $table->dropForeign('volunteering_ibfk_2');
        });
    }
};
