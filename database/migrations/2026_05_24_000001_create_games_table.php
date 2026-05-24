<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('invite_code', 12)->unique();
            $table->string('mode', 16)->default('network');
            $table->string('status', 16)->default('waiting');
            $table->unsignedSmallInteger('end_score')->default(100);
            $table->unsignedTinyInteger('current_round')->default(0);
            // current_player_id and round_ender_id added in migration 5 (circular FK)
            $table->unsignedBigInteger('current_player_id')->nullable();
            $table->string('turn_phase', 8)->nullable();
            $table->tinyInteger('held_card_value')->nullable();
            $table->json('draw_pile')->nullable();
            $table->json('discard_pile')->nullable();
            $table->unsignedBigInteger('round_ender_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
