<?php

use App\Actions\Games\CreateGame;
use App\Actions\Games\JoinGame;
use App\Actions\Games\StartGame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Start a 2-player game and return the game and the current player.
 *
 * @return array{game: Game, current: GamePlayer, other: GamePlayer}
 */
function startGame(): array
{
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $team = $host->currentTeam;

    $game = (new CreateGame)($host, $team);
    (new JoinGame)($game, $guest);
    (new StartGame(app(GameEngine::class)))($game);

    $game->refresh();
    $current = $game->players()->where('id', $game->current_player_id)->firstOrFail();
    $other = $game->players()->where('id', '!=', $game->current_player_id)->firstOrFail();

    return compact('game', 'current', 'other');
}
