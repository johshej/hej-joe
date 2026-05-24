<?php

use App\Actions\Games\CreateGame;
use App\Events\PlayerJoined;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('PlayerJoined event is broadcast immediately when a player joins via invite link', function () {
    Event::fake([PlayerJoined::class]);

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);

    $this->actingAs($guest)->get(route('games.join', ['inviteCode' => $game->invite_code]));

    Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) use ($game, $guest) {
        return $event->game->id === $game->id
            && $event->player->user_id === $guest->id;
    });
});

test('PlayerJoined event is not dispatched when the same player rejoins', function () {
    Event::fake([PlayerJoined::class]);

    $host = User::factory()->create();
    $team = $host->currentTeam;
    $game = (new CreateGame)($host, $team);

    // Host visits join link — already a player, should not fire again
    $this->actingAs($host)->get(route('games.join', ['inviteCode' => $game->invite_code]));

    Event::assertNotDispatched(PlayerJoined::class);
});
