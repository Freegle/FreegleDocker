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
        Schema::table('memberships_yahoo', function (Blueprint $table) {
            $table->foreign(['membershipid'], '_memberships_yahoo_ibfk_1')->references(['id'])->on('memberships')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['emailid'], 'memberships_yahoo_ibfk_1')->references(['id'])->on('users_emails')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memberships_yahoo', function (Blueprint $table) {
            $table->dropForeign('_memberships_yahoo_ibfk_1');
            $table->dropForeign('memberships_yahoo_ibfk_1');
        });
    }
};
