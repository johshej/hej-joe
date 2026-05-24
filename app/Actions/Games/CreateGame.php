<?php

namespace App\Actions\Games;

use App\Enums\GameMode;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;

class CreateGame
{
    public function __invoke(User $user, Team $team, GameMode $mode = GameMode::Network, int $endScore = 100): Game
    {
        $game = Game::create([
            'team_id' => $team->id,
            'created_by' => $user->id,
            'mode' => $mode,
            'end_score' => $endScore,
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 0,
        ]);

        return $game;
    }
}
