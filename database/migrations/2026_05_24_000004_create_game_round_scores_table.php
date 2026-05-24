<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_round_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round_number');
            $table->smallInteger('raw_score');
            $table->smallInteger('adjusted_score');
            $table->boolean('is_doubled')->default(false);
            $table->boolean('triggered_round_end')->default(false);
            $table->timestamps();

            $table->unique(['game_id', 'game_player_id', 'round_number']);
            $table->index(['game_id', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_round_scores');
    }
};
