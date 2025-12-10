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
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign(['heldby'], '_messages_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fromuser'], '_messages_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['locationid'], '_messages_ibfk_3')->references(['id'])->on('locations')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign('_messages_ibfk_1');
            $table->dropForeign('_messages_ibfk_2');
            $table->dropForeign('_messages_ibfk_3');
        });
    }
};
