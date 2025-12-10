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
        Schema::create('logs_api', function (Blueprint $table) {
            $table->comment('Log of all API requests and responses');
            $table->bigIncrements('id')->unique('id');
            $table->timestamp('date')->useCurrent()->index('date');
            $table->bigInteger('userid')->nullable()->index('userid');
            $table->string('ip', 20)->nullable()->index('ip');
            $table->string('session')->index('session');
            $table->longText('request');
            $table->longText('response');

            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_api');
    }
};
