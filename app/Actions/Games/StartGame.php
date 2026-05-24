<?php

namespace App\Actions\Games;

use App\Enums\GameStatus;
use App\Enums\TurnPhase;
use App\Events\GameStarted;
use App\Models\Game;
use App\Models\PlayerCard;
use App\Services\GameEngine;
use Illuminate\Support\Facades\DB;

class StartGame
{
    public function __construct(private readonly GameEngine $engine) {}

    public function __invoke(Game $game): void
    {
        DB::transaction(function () use ($game) {
            $players = $game->players()->orderBy('seat')->get();
            $deck = $this->engine->buildDeck();
            $cards = [];

            foreach ($players as $player) {
                $faceUpPositions = $this->engine->initialFaceUpPositions();

                for ($position = 0; $position < 12; $position++) {
                    $cards[] = [
                        'game_player_id' => $player->id,
                        'position' => $position,
                        'value' => array_pop($deck),
                        'is_face_up' => in_array($position, $faceUpPositions),
                    ];
                }
            }

            PlayerCard::insert($cards);

            $firstPlayer = $this->engine->firstPlayerByStartCards($players, $cards);
            $topDiscard = array_pop($deck);

            $game->update([
                'status' => GameStatus::Active,
                'current_round' => 1,
                'current_player_id' => $firstPlayer->id,
                'turn_phase' => TurnPhase::Draw,
                'draw_pile' => array_values($deck),
                'discard_pile' => [$topDiscard],
            ]);
        });

        broadcast(new GameStarted($game));
    }
}
