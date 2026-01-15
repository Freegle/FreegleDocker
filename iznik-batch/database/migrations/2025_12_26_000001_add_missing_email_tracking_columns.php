<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds columns that exist in the Go model but were missing from the original migration.
     */
    public function up(): void
    {
        Schema::table('email_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('email_tracking', 'bounce_type')) {
                $table->string('bounce_type', 50)->nullable()->after('bounced_at');
            }
            if (!Schema::hasColumn('email_tracking', 'opened_via')) {
                $table->string('opened_via', 50)->nullable()->after('opened_at');
            }
            if (!Schema::hasColumn('email_tracking', 'clicked_link')) {
                $table->string('clicked_link', 2048)->nullable()->after('clicked_at');
            }
            if (!Schema::hasColumn('email_tracking', 'scroll_depth_percent')) {
                $table->unsignedTinyInteger('scroll_depth_percent')->nullable()->after('clicked_link');
            }
            if (!Schema::hasColumn('email_tracking', 'images_loaded')) {
                $table->unsignedSmallInteger('images_loaded')->default(0)->after('scroll_depth_percent');
            }
            if (!Schema::hasColumn('email_tracking', 'links_clicked')) {
                $table->unsignedSmallInteger('links_clicked')->default(0)->after('images_loaded');
            }
            if (!Schema::hasColumn('email_tracking', 'has_amp')) {
                $table->boolean('has_amp')->default(false)->after('unsubscribed_at');
            }
            if (!Schema::hasColumn('email_tracking', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('has_amp');
            }
            if (!Schema::hasColumn('email_tracking', 'replied_via')) {
                $table->string('replied_via', 50)->nullable()->after('replied_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_tracking', function (Blueprint $table) {
            $table->dropColumn([
                'bounce_type',
                'opened_via',
                'clicked_link',
                'scroll_depth_percent',
                'images_loaded',
                'links_clicked',
                'has_amp',
                'replied_at',
                'replied_via',
            ]);
        });
    }
};
