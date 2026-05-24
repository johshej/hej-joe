<?php

namespace Database\Factories;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'mode' => GameMode::Network,
            'status' => GameStatus::Waiting,
            'end_score' => 100,
            'current_round' => 0,
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => GameMode::Local,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GameStatus::Active,
            'current_round' => 1,
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GameStatus::Finished,
        ]);
    }
}
