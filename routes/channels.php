<?php

use App\Models\Game;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, int $id) {
    return (int) $user->id === $id;
});

Broadcast::channel('game.{gameId}', function ($user, int $gameId): array|bool {
    $game = Game::find($gameId);

    if (! $game) {
        return false;
    }

    $player = $game->players()->where('user_id', $user->id)->first();

    if (! $player) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'seat' => $player->seat,
    ];
});
