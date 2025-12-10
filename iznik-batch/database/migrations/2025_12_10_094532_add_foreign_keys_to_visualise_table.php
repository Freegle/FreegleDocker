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
        Schema::table('visualise', function (Blueprint $table) {
            $table->foreign(['attid'], '_visualise_ibfk_4')->references(['id'])->on('messages_attachments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'], 'visualise_ibfk_1')->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fromuser'], 'visualise_ibfk_2')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['touser'], 'visualise_ibfk_3')->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visualise', function (Blueprint $table) {
            $table->dropForeign('_visualise_ibfk_4');
            $table->dropForeign('visualise_ibfk_1');
            $table->dropForeign('visualise_ibfk_2');
            $table->dropForeign('visualise_ibfk_3');
        });
    }
};
