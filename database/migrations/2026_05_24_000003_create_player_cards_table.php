<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position'); // 0-11; col = floor(pos/3), row = pos % 3
            $table->tinyInteger('value');            // -2 to 12
            $table->boolean('is_face_up')->default(false);
            // No timestamps — high write frequency

            $table->unique(['game_player_id', 'position']);
            $table->index(['game_player_id', 'is_face_up']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_cards');
    }
};
