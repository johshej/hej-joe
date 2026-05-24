<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->change();
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable(false)->change();
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
