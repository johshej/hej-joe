<?php

namespace App\Actions\Games;

use App\Enums\GameMode;
use App\Models\Game;
use App\Models\GamePlayer;

class StartLocalGuestGame
{
    public function __construct(private readonly StartGame $startGame) {}

    /**
     * @param  array<int, string>  $playerNames  2–4 names
     */
    public function __invoke(array $playerNames, int $endScore = 100): Game
    {
        $game = Game::create([
            'team_id' => null,
            'created_by' => null,
            'mode' => GameMode::Local,
            'end_score' => $endScore,
        ]);

        foreach (array_values($playerNames) as $seat => $name) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => null,
                'guest_name' => trim($name),
                'seat' => $seat,
            ]);
        }

        ($this->startGame)($game);

        return $game->fresh();
    }
}
