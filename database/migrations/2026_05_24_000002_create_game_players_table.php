<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name', 64)->nullable();
            $table->unsignedTinyInteger('seat');
            $table->smallInteger('total_score')->default(0);
            $table->boolean('is_winner')->default(false);
            $table->boolean('has_finished_revealing')->default(false);
            $table->timestamps();

            $table->unique(['game_id', 'seat']);
            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
