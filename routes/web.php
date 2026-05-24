<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('games', 'pages::games.index')->name('games.index');
        Route::livewire('games/{game}', 'pages::games.play')->name('games.play');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
    Route::livewire('games/join/{inviteCode}', 'pages::games.join')->name('games.join');
});

require __DIR__.'/settings.php';
