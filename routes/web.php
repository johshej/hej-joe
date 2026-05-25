<?php

use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Team;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('/rules', 'pages::rules')->name('rules');

Route::livewire('local/{game}', 'pages::games.local')->name('games.local');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        // Backward-compat redirect so existing links to /dashboard still work.
        Route::get('dashboard', fn (Team $current_team) => redirect()->route('games.index', ['current_team' => $current_team->slug]))->name('dashboard');

        Route::livewire('games', 'pages::games.index')->name('games.index');
        Route::livewire('games/{game}', 'pages::games.play')->name('games.play');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
    Route::livewire('games/join/{inviteCode}', 'pages::games.join')->name('games.join');
});

require __DIR__.'/settings.php';
