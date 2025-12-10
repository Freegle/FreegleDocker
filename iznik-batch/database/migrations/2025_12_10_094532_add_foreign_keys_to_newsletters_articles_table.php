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
        Schema::table('newsletters_articles', function (Blueprint $table) {
            $table->foreign(['newsletterid'], 'newsletters_articles_ibfk_1')->references(['id'])->on('newsletters')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['photoid'], 'newsletters_articles_ibfk_2')->references(['id'])->on('newsletters_images')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsletters_articles', function (Blueprint $table) {
            $table->dropForeign('newsletters_articles_ibfk_1');
            $table->dropForeign('newsletters_articles_ibfk_2');
        });
    }
};
