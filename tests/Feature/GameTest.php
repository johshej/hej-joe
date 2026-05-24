<?php

use App\Actions\Games\CreateGame;
use App\Actions\Games\JoinGame;
use App\Actions\Games\StartGame;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\TurnPhase;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('games index page can be rendered', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->get(route('games.index', ['current_team' => $team->slug]));

    $response->assertOk();
});

test('game can be created', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user);

    Livewire::test('pages::games.index', ['currentTeam' => $team])
        ->set('mode', 'network')
        ->set('endScore', 100)
        ->call('createGame')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('games', [
        'team_id' => $team->id,
        'created_by' => $user->id,
        'mode' => 'network',
        'end_score' => 100,
        'status' => 'waiting',
    ]);
});

test('game gets a unique invite code on creation', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $game = (new CreateGame)($user, $team);

    expect($game->invite_code)->not->toBeEmpty();
    expect(strlen($game->invite_code))->toBe(8);
});

test('creator is automatically added as first player', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $game = (new CreateGame)($user, $team);

    expect($game->players()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($game->players()->first()->seat)->toBe(0);
});

test('second player can join via invite code', function () {
    $host = User::factory()->create();
    $joiner = User::factory()->create();
    $team = $host->currentTeam;
    $team->members()->attach($joiner, ['role' => \App\Enums\TeamRole::Member->value]);

    $game = (new CreateGame)($host, $team);

    (new JoinGame)($game, $joiner);

    expect($game->players()->count())->toBe(2);
    expect($game->players()->where('user_id', $joiner->id)->exists())->toBeTrue();
});

test('same user cannot join game twice', function () {
    $host = User::factory()->create();
    $team = $host->currentTeam;
    $game = (new CreateGame)($host, $team);

    $player1 = (new JoinGame)($game, $host);
    $player2 = (new JoinGame)($game, $host);

    expect($player1->id)->toBe($player2->id);
    expect($game->players()->count())->toBe(1);
});

test('game cannot have more than 8 players', function () {
    $host = User::factory()->create();
    $team = $host->currentTeam;
    $game = (new CreateGame)($host, $team);

    for ($i = 0; $i < 7; $i++) {
        $user = User::factory()->create();
        (new JoinGame)($game, $user);
    }

    $ninth = User::factory()->create();

    expect(fn () => (new JoinGame)($game, $ninth))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('game can be started with at least 2 players', function () {
    $host = User::factory()->create();
    $player2 = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $player2);

    (new StartGame(app(\App\Services\GameEngine::class)))($game);

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Active);
    expect($game->current_round)->toBe(1);
    expect($game->current_player_id)->not->toBeNull();
    expect($game->turn_phase)->toBe(TurnPhase::Draw);
    expect($game->draw_pile)->not->toBeEmpty();
});

test('each player gets 12 cards when game starts', function () {
    $host = User::factory()->create();
    $player2 = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $player2);

    (new StartGame(app(\App\Services\GameEngine::class)))($game);

    foreach ($game->players as $player) {
        expect($player->cards()->count())->toBe(12);
    }
});

test('each player has exactly 2 face-up cards at game start', function () {
    $host = User::factory()->create();
    $player2 = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $player2);

    (new StartGame(app(\App\Services\GameEngine::class)))($game);

    foreach ($game->players as $player) {
        expect($player->cards()->where('is_face_up', true)->count())->toBe(2);
    }
});

test('game play page renders in waiting status', function () {
    $host = User::factory()->create();
    $player2 = User::factory()->create();
    $team = $host->currentTeam;
    $team->members()->attach($player2, ['role' => \App\Enums\TeamRole::Member->value]);

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $player2);

    $response = $this->actingAs($host)->get(
        route('games.play', ['current_team' => $team->slug, 'game' => $game->invite_code])
    );

    $response->assertOk();
});

test('guest can join with invite code via join route', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);

    $this->actingAs($guest)->get(route('games.join', ['inviteCode' => $game->invite_code]));

    expect($game->players()->where('user_id', $guest->id)->exists())->toBeTrue();
});

test('local game can be created', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $game = (new CreateGame)($user, $team, GameMode::Local);

    expect($game->mode)->toBe(GameMode::Local);
});

test('end score defaults to 100', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $game = (new CreateGame)($user, $team);

    expect($game->end_score)->toBe(100);
});

test('end score can be configured', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $game = (new CreateGame)($user, $team, GameMode::Network, 50);

    expect($game->end_score)->toBe(50);
});
