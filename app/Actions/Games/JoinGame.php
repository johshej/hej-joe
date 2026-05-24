<?php

namespace App\Actions\Games;

use App\Enums\TeamRole;
use App\Events\PlayerJoined;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class JoinGame
{
    public function __invoke(Game $game, User $user): GamePlayer
    {
        if ($game->players()->count() >= 8) {
            throw ValidationException::withMessages(['game' => __('This game is full.')]);
        }

        $existingPlayer = $game->players()->where('user_id', $user->id)->first();

        if ($existingPlayer) {
            return $existingPlayer;
        }

        $nextSeat = $game->players()->max('seat') + 1;

        if (! $game->team->members()->where('user_id', $user->id)->exists()) {
            $game->team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Member->value,
            ]);
        }

        $player = GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => $nextSeat,
        ]);

        broadcast(new PlayerJoined($game, $player))->toOthers();

        return $player;
    }
}
