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
        Schema::table('spam_users', function (Blueprint $table) {
            $table->foreign(['userid'], 'spam_users_ibfk_1')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['byuserid'], 'spam_users_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['heldby'], 'spam_users_ibfk_4')->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spam_users', function (Blueprint $table) {
            $table->dropForeign('spam_users_ibfk_1');
            $table->dropForeign('spam_users_ibfk_2');
            $table->dropForeign('spam_users_ibfk_4');
        });
    }
};
