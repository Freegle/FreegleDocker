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
        if (!Schema::hasTable('email_tracking')) {
            Schema::create('email_tracking', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tracking_id', 32)->unique();
                $table->string('email_type', 50)->index();
                $table->unsignedBigInteger('userid')->nullable()->index();
                $table->unsignedBigInteger('groupid')->nullable()->index();
                $table->string('recipient_email', 255)->index();
                $table->string('subject', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('sent_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('bounced_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('clicked_at')->nullable();
                $table->timestamp('unsubscribed_at')->nullable();
                $table->timestamps();

                $table->index(['email_type', 'sent_at']);
                $table->index(['userid', 'sent_at']);
            });
        }

        if (!Schema::hasTable('email_tracking_clicks')) {
            Schema::create('email_tracking_clicks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('email_tracking_id')->index();
                $table->string('link_url', 2048);
                $table->string('link_position', 50)->nullable();
                $table->string('action', 50)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestamp('clicked_at')->nullable()->index();

                $table->foreign('email_tracking_id')
                    ->references('id')
                    ->on('email_tracking')
                    ->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('email_tracking_images')) {
            Schema::create('email_tracking_images', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('email_tracking_id')->index();
                $table->string('image_position', 50);
                $table->unsignedTinyInteger('estimated_scroll_percent')->nullable();
                $table->timestamp('loaded_at')->nullable()->index();

                $table->foreign('email_tracking_id')
                    ->references('id')
                    ->on('email_tracking')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_tracking_images');
        Schema::dropIfExists('email_tracking_clicks');
        Schema::dropIfExists('email_tracking');
    }
};
