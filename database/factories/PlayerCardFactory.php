<?php

namespace Database\Factories;

use App\Models\GamePlayer;
use App\Models\PlayerCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerCard>
 */
class PlayerCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_player_id' => GamePlayer::factory(),
            'position' => 0,
            'value' => fake()->numberBetween(-2, 12),
            'is_face_up' => false,
        ];
    }

    public function faceUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_face_up' => true,
        ]);
    }
}
