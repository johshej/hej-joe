<?php

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Livewire\Livewire;

test('guest can create a local game from the home page', function () {
    Livewire::test('pages::home')
        ->set('playerNames', ['Alice', 'Bob'])
        ->set('endScore', 100)
        ->call('startLocalGame')
        ->assertHasNoErrors()
        ->assertRedirect();

    $game = Game::latest()->first();
    expect($game->mode)->toBe(GameMode::Local);
    expect($game->status)->toBe(GameStatus::Active);
    expect($game->players()->count())->toBe(2);
});

test('logged-in user can create a local game from the home page', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::home')
        ->set('playerNames', ['Alice', 'Bob'])
        ->set('endScore', 100)
        ->call('startLocalGame')
        ->assertHasNoErrors()
        ->assertRedirect();

    $game = Game::latest()->first();
    expect($game->mode)->toBe(GameMode::Local);
    expect($game->status)->toBe(GameStatus::Active);
    expect($game->players()->count())->toBe(2);
});

test('logged-in user sees the home page without being redirected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::home')
        ->assertHasNoErrors();
});

test('GET / returns 200 for logged-in user without redirect', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $response->assertSee('Start game');
});

test('local game from home page redirects to games.local route', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::home')
        ->set('playerNames', ['Alice', 'Bob'])
        ->call('startLocalGame');

    $game = Game::latest()->first();
    $component->assertRedirect(route('games.local', ['game' => $game->invite_code]));
});

test('games.local page renders correctly for logged-in user after game creation', function () {
    $user = User::factory()->create();

    // Create the game via the component
    Livewire::actingAs($user)
        ->test('pages::home')
        ->set('playerNames', ['Alice', 'Bob'])
        ->call('startLocalGame');

    $game = Game::latest()->first();

    // Hit the local game URL as the logged-in user
    $response = $this->actingAs($user)->get(route('games.local', ['game' => $game->invite_code]));

    $response->assertOk();
    $response->assertSee('Alice');
    $response->assertSee('Bob');
});
