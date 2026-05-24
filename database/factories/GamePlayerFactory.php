<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GamePlayer>
 */
class GamePlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'guest_name' => null,
            'seat' => 0,
            'total_score' => 0,
            'is_winner' => false,
            'has_finished_revealing' => false,
        ];
    }

    public function guest(?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'guest_name' => $name ?? fake()->firstName(),
        ]);
    }

    public function winner(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_winner' => true,
        ]);
    }
}
