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
        Schema::table('alerts_tracking', function (Blueprint $table) {
            $table->foreign(['emailid'], '_alerts_tracking_ibfk_3')->references(['id'])->on('users_emails')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['alertid'], 'alerts_tracking_ibfk_1')->references(['id'])->on('alerts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'], 'alerts_tracking_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'], 'alerts_tracking_ibfk_4')->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts_tracking', function (Blueprint $table) {
            $table->dropForeign('_alerts_tracking_ibfk_3');
            $table->dropForeign('alerts_tracking_ibfk_1');
            $table->dropForeign('alerts_tracking_ibfk_2');
            $table->dropForeign('alerts_tracking_ibfk_4');
        });
    }
};
