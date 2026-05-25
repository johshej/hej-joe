<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rules – Hej-Joe')] #[Layout('layouts.guest')] class extends Component {}; ?>

<div class="min-h-screen bg-zinc-950 text-white">
    {{-- Nav --}}
    <header class="flex items-center justify-between px-6 py-4">
        <a href="{{ route('home') }}" wire:navigate class="text-lg font-bold tracking-tight hover:text-zinc-300 transition">Hej-Joe</a>
        <div class="flex items-center gap-3">
            @auth
                <a href="{{ route('games.index', ['current_team' => Auth::user()->currentTeam->slug]) }}" wire:navigate class="text-sm text-zinc-400 hover:text-white transition">{{ __('My games') }}</a>
                <a href="{{ route('profile.edit') }}" wire:navigate class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-900 hover:bg-zinc-100 transition">{{ Auth::user()->name }}</a>
            @else
                <a href="{{ route('login') }}" class="text-sm text-zinc-400 hover:text-white transition">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-900 hover:bg-zinc-100 transition">{{ __('Register') }}</a>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-6 py-12 space-y-12">

        <div class="text-center">
            <h1 class="text-4xl font-bold tracking-tight mb-3">{{ __('How to play Hej-Joe') }}</h1>
            <p class="text-zinc-400">{{ __('A fast card game for 2–8 players. Lowest score wins.') }}</p>
        </div>

        {{-- Objective --}}
        <section class="space-y-3">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('Objective') }}</h2>
            <p class="text-zinc-300">
                {{ __('Keep your total score as low as possible across multiple rounds. Win the game by being the first to hit exactly 50 or 100 points — or be the player with the lowest score when someone exceeds the target.') }}
            </p>
        </section>

        {{-- Setup --}}
        <section class="space-y-3">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('Setup') }}</h2>
            <ul class="space-y-2 text-zinc-300">
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('Each player receives 12 cards arranged face-down in a 4-column, 3-row grid.') }}</li>
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('At the start of each round, every player flips 2 of their cards face-up.') }}</li>
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('The player with the highest sum of their 2 starting face-up cards goes first.') }}</li>
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('One card is dealt face-up to start the discard pile; the rest form the draw pile.') }}</li>
            </ul>
        </section>

        {{-- Turn --}}
        <section class="space-y-4">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('A turn') }}</h2>
            <div class="space-y-4">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5">
                    <h3 class="font-semibold mb-1">{{ __('1. Draw a card') }}</h3>
                    <p class="text-sm text-zinc-400">{{ __('Take the top card from the draw pile (hidden), or take the top card from the discard pile (visible).') }}</p>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5">
                    <h3 class="font-semibold mb-1">{{ __('2. Use the card') }}</h3>
                    <p class="text-sm text-zinc-400 mb-2">{{ __('You have two options:') }}</p>
                    <ul class="space-y-1 text-sm text-zinc-400">
                        <li class="flex gap-2"><span class="shrink-0 text-zinc-500">A.</span>{{ __('Swap it with any card in your grid (even a face-down one). The replaced card goes to the discard pile and the new card is face-up.') }}</li>
                        <li class="flex gap-2"><span class="shrink-0 text-zinc-500">B.</span>{{ __('Discard it, then flip one of your own face-down cards face-up.') }}</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- Column elimination --}}
        <section class="space-y-3">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('Column elimination') }}</h2>
            <p class="text-zinc-300">
                {{ __('When all 3 cards in a column are face-up and have the same value, that column is immediately removed from the game. The 3 cards are added to the discard pile (on top), making the value visible for the next player.') }}
            </p>
        </section>

        {{-- End of round --}}
        <section class="space-y-3">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('End of a round') }}</h2>
            <p class="text-zinc-300">
                {{ __('The round ends when a player has all remaining cards face-up. Every other player gets exactly one more turn, then all cards are revealed and scored.') }}
            </p>
            <p class="text-zinc-300">
                {{ __('After scoring, review your cards and press Ready when you\'re done. The next round then starts.') }}
            </p>
        </section>

        {{-- Scoring --}}
        <section class="space-y-4">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('Scoring') }}</h2>

            <p class="text-zinc-300">{{ __('Each card scores its face value:') }}</p>

            <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">
                @foreach ([
                    ['label' => '-2', 'score' => '-2', 'bg' => 'bg-blue-900/60 text-blue-200'],
                    ['label' => '-1', 'score' => '-1', 'bg' => 'bg-blue-900/60 text-blue-200'],
                    ['label' => '0', 'score' => '0', 'bg' => 'bg-zinc-700/60 text-zinc-300'],
                    ['label' => '1 – 9', 'score' => __('face value'), 'bg' => 'bg-yellow-900/60 text-yellow-200'],
                    ['label' => '10 – 12', 'score' => __('face value'), 'bg' => 'bg-red-900/60 text-red-200'],
                ] as $tier)
                    <div class="rounded-lg {{ $tier['bg'] }} p-3 text-center">
                        <div class="text-lg font-bold">{{ $tier['label'] }}</div>
                        <div class="text-xs opacity-75">{{ $tier['score'] }} {{ __('pts') }}</div>
                    </div>
                @endforeach
            </div>

            <div class="space-y-3 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5 text-sm text-zinc-300">
                <div class="flex gap-3">
                    <span class="shrink-0 font-semibold text-zinc-400">{{ __('120+ rule') }}</span>
                    <span>{{ __('If your raw round score is 120 or more, your round score becomes −7 instead.') }}</span>
                </div>
                <div class="flex gap-3">
                    <span class="shrink-0 font-semibold text-zinc-400">{{ __('Doubling') }}</span>
                    <span>{{ __('If you end the round but your (possibly capped) score is still higher than every other player\'s raw score, your round score doubles.') }}</span>
                </div>
            </div>
        </section>

        {{-- Winning --}}
        <section class="space-y-3">
            <h2 class="text-xl font-semibold border-b border-zinc-800 pb-2">{{ __('Winning the game') }}</h2>
            <ul class="space-y-2 text-zinc-300">
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('The game ends when a player\'s total score hits or exceeds the target (default: 100).') }}</li>
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('If a player hits exactly 50 or exactly 100, they win immediately — regardless of others\' scores.') }}</li>
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('Tie-break: a score of 50 beats 100. If still tied, the player with the lower round score in the last round wins. If still equal, it\'s a shared win.') }}</li>
                <li class="flex gap-2"><span class="mt-1 shrink-0 text-zinc-500">–</span>{{ __('Otherwise, the player with the lowest total when the game ends wins.') }}</li>
            </ul>
        </section>

        <div class="text-center pt-4">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-100 transition">
                {{ __('Back to home') }}
            </a>
        </div>

    </main>
</div>
