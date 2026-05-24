<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRoundScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameRoundScore>
 */
class GameRoundScoreFactory extends Factory
{
    public function definition(): array
    {
        $raw = fake()->numberBetween(-8, 48);

        return [
            'game_id' => Game::factory(),
            'game_player_id' => GamePlayer::factory(),
            'round_number' => 1,
            'raw_score' => $raw,
            'adjusted_score' => $raw,
            'is_doubled' => false,
            'triggered_round_end' => false,
        ];
    }
}
