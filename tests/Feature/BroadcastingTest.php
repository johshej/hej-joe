<?php

use App\Actions\Games\CreateGame;
use App\Actions\Games\JoinGame;
use App\Actions\Games\StartGame;
use App\Actions\Games\TakeTurn;
use App\Enums\TurnPhase;
use App\Events\GameStarted;
use App\Events\GameStateUpdated;
use App\Events\PlayerJoined;
use App\Models\User;
use App\Services\GameEngine;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Event;

// ─── Event configuration ───────────────────────────────────────────────────────

test('GameStateUpdated broadcasts on the game presence channel', function () {
    ['game' => $game] = startGame();

    $event = new GameStateUpdated($game);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PresenceChannel::class);
    expect($channels[0]->name)->toBe("presence-game.{$game->id}");
    expect($event->broadcastAs())->toBe('state.updated');
    expect($event->broadcastWith())->toBe(['game_id' => $game->id]);
});

test('PlayerJoined broadcasts on the game presence channel', function () {
    $host = User::factory()->create();
    $team = $host->currentTeam;
    $game = (new CreateGame)($host, $team);
    $player = $game->players()->firstOrFail();

    $event = new PlayerJoined($game, $player);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PresenceChannel::class);
    expect($channels[0]->name)->toBe("presence-game.{$game->id}");
    expect($event->broadcastAs())->toBe('player.joined');
});

test('GameStarted broadcasts on the game presence channel', function () {
    ['game' => $game] = startGame();

    $event = new GameStarted($game);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PresenceChannel::class);
    expect($channels[0]->name)->toBe("presence-game.{$game->id}");
    expect($event->broadcastAs())->toBe('game.started');
});

// ─── JoinGame ─────────────────────────────────────────────────────────────────

test('JoinGame dispatches PlayerJoined for the new player', function () {
    Event::fake([PlayerJoined::class]);

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $guest);

    Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) use ($game, $guest) {
        return $event->game->id === $game->id
            && $event->player->user_id === $guest->id;
    });
});

test('JoinGame does not dispatch PlayerJoined when the player already exists', function () {
    Event::fake([PlayerJoined::class]);

    $host = User::factory()->create();
    $team = $host->currentTeam;
    $game = (new CreateGame)($host, $team);

    // Join twice — second call should be a no-op.
    $player = $game->players()->firstOrFail();
    (new JoinGame)($game, $host);

    Event::assertNotDispatched(PlayerJoined::class);
});

// ─── StartGame ────────────────────────────────────────────────────────────────

test('StartGame dispatches GameStarted', function () {
    Event::fake([GameStarted::class]);

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $guest);
    (new StartGame(app(GameEngine::class)))($game);

    Event::assertDispatched(GameStarted::class, fn (GameStarted $event) => $event->game->id === $game->id);
});

// ─── TakeTurn ─────────────────────────────────────────────────────────────────

test('drawFromPile dispatches GameStateUpdated', function () {
    Event::fake([GameStateUpdated::class]);

    ['game' => $game, 'current' => $current] = startGame();

    (new TakeTurn(app(GameEngine::class)))->drawFromPile($game, $current);

    Event::assertDispatched(GameStateUpdated::class, fn (GameStateUpdated $e) => $e->game->id === $game->id);
});

test('takeFromDiscard dispatches GameStateUpdated', function () {
    Event::fake([GameStateUpdated::class]);

    ['game' => $game, 'current' => $current] = startGame();
    $game->update(['discard_pile' => [3, 7]]);

    (new TakeTurn(app(GameEngine::class)))->takeFromDiscard($game, $current);

    Event::assertDispatched(GameStateUpdated::class, fn (GameStateUpdated $e) => $e->game->id === $game->id);
});

test('placeCard dispatches GameStateUpdated', function () {
    Event::fake([GameStateUpdated::class]);

    ['game' => $game, 'current' => $current] = startGame();
    $game->update(['turn_phase' => TurnPhase::Held, 'held_card_value' => 4, 'discard_pile' => [1]]);

    (new TakeTurn(app(GameEngine::class)))->placeCard($game, $current, 0);

    Event::assertDispatched(GameStateUpdated::class, fn (GameStateUpdated $e) => $e->game->id === $game->id);
});

test('discardHeld dispatches GameStateUpdated', function () {
    Event::fake([GameStateUpdated::class]);

    ['game' => $game, 'current' => $current] = startGame();
    $game->update(['turn_phase' => TurnPhase::Held, 'held_card_value' => 6, 'discard_pile' => [2]]);

    (new TakeTurn(app(GameEngine::class)))->discardHeld($game, $current);

    Event::assertDispatched(GameStateUpdated::class, fn (GameStateUpdated $e) => $e->game->id === $game->id);
});

test('undoDiscard dispatches GameStateUpdated', function () {
    Event::fake([GameStateUpdated::class]);

    ['game' => $game, 'current' => $current] = startGame();
    $game->update(['turn_phase' => TurnPhase::Held, 'held_card_value' => 6, 'discard_pile' => [2]]);
    (new TakeTurn(app(GameEngine::class)))->discardHeld($game, $current);
    $game->refresh();

    Event::clearResolvedInstances();
    Event::fake([GameStateUpdated::class]);

    (new TakeTurn(app(GameEngine::class)))->undoDiscard($game, $current);

    Event::assertDispatched(GameStateUpdated::class, fn (GameStateUpdated $e) => $e->game->id === $game->id);
});

test('flipCard dispatches GameStateUpdated', function () {
    Event::fake([GameStateUpdated::class]);

    ['game' => $game, 'current' => $current] = startGame();
    $current->cards()->where('position', 0)->update(['value' => 3, 'is_face_up' => false]);
    $game->update(['turn_phase' => TurnPhase::Flip]);

    (new TakeTurn(app(GameEngine::class)))->flipCard($game, $current, 0);

    Event::assertDispatched(GameStateUpdated::class, fn (GameStateUpdated $e) => $e->game->id === $game->id);
});

// ─── No ->toOthers() regression ───────────────────────────────────────────────

test('GameStateUpdated is dispatched even when no X-Socket-ID header is present', function () {
    Event::fake([GameStateUpdated::class]);

    // Simulate environment where Echo has not yet connected (no socket ID in headers).
    // Previously ->toOthers() would throw a PusherException here and silently kill the broadcast.
    ['game' => $game, 'current' => $current] = startGame();

    (new TakeTurn(app(GameEngine::class)))->drawFromPile($game, $current);

    Event::assertDispatched(GameStateUpdated::class);
});
