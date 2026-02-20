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
        if (Schema::hasTable('socialactions')) {
            return;
        }

        Schema::create('socialactions', function (Blueprint $table) {
            $table->comment('Pending social media actions for group moderators');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid');
            $table->string('action_type', 50)->index('action_type');
            $table->string('uid', 128)->nullable();
            $table->timestamp('created')->useCurrent();
            $table->timestamp('performed')->nullable();

            $table->index(['groupid', 'performed'], 'pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('socialactions');
    }
};
