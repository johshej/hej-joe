<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreign('current_player_id')->references('id')->on('game_players')->nullOnDelete();
            $table->foreign('round_ender_id')->references('id')->on('game_players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['current_player_id']);
            $table->dropForeign(['round_ender_id']);
        });
    }
};
