<?php

use App\Actions\Games\StartLocalGuestGame;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Hej-Joe')] #[Layout('layouts.guest')] class extends Component {
    /** @var array<int, string> */
    public array $playerNames = ['', ''];

    public int $endScore = 100;

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectRoute('games.index', ['current_team' => Auth::user()->currentTeam->slug], navigate: true);
        }
    }

    public function addPlayer(): void
    {
        if (count($this->playerNames) < 4) {
            $this->playerNames[] = '';
        }
    }

    public function removePlayer(int $index): void
    {
        if (count($this->playerNames) > 2) {
            array_splice($this->playerNames, $index, 1);
            $this->playerNames = array_values($this->playerNames);
        }
    }

    public function startLocalGame(StartLocalGuestGame $action): void
    {
        $this->validate([
            'playerNames' => 'required|array|min:2|max:4',
            'playerNames.*' => 'required|string|min:1|max:32',
            'endScore' => 'required|integer|min:10|max:999',
        ]);

        $game = $action($this->playerNames, $this->endScore);

        $this->redirectRoute('games.local', ['game' => $game->invite_code]);
    }
}; ?>

<div class="min-h-screen bg-zinc-950 text-white">
    {{-- Nav --}}
    <header class="flex items-center justify-between px-6 py-4">
        <span class="text-lg font-bold tracking-tight">Hej-Joe</span>
        <div class="flex items-center gap-3">
            <a href="{{ route('login') }}" class="text-sm text-zinc-400 hover:text-white transition">{{ __('Sign in') }}</a>
            <a href="{{ route('register') }}" class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-zinc-900 hover:bg-zinc-100 transition">{{ __('Register') }}</a>
        </div>
    </header>

    {{-- Hero --}}
    <section class="mx-auto max-w-5xl px-6 py-16 text-center">
        <h1 class="mb-4 text-5xl font-bold tracking-tight sm:text-6xl">Hej-Joe</h1>
        <p class="mb-3 text-xl text-zinc-400">{{ __('A fast, strategic card game for 2–8 players.') }}</p>
        <p class="text-zinc-500">{{ __('Based on Skyjo — pick up, swap, and discard cards to build the lowest score.') }}</p>
    </section>

    {{-- Two-column CTA --}}
    <section class="mx-auto max-w-5xl grid grid-cols-1 gap-6 px-6 pb-16 md:grid-cols-2">
        {{-- Local game card --}}
        <div class="rounded-2xl border border-zinc-800 bg-zinc-900 p-6">
            <div class="mb-4">
                <h2 class="mb-1 text-xl font-semibold">{{ __('Play local') }}</h2>
                <p class="text-sm text-zinc-400">{{ __('All players share this screen. No account needed.') }}</p>
            </div>

            <form wire:submit="startLocalGame" class="space-y-4">
                @foreach ($playerNames as $index => $name)
                    <div class="flex items-center gap-2">
                        <flux:input
                            wire:model="playerNames.{{ $index }}"
                            :placeholder="__('Player :n', ['n' => $index + 1])"
                            class="flex-1"
                            maxlength="32"
                        />
                        @if (count($playerNames) > 2)
                            <button
                                type="button"
                                wire:click="removePlayer({{ $index }})"
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-zinc-700 text-zinc-400 hover:border-zinc-500 hover:text-white transition"
                            >
                                <flux:icon name="x-mark" class="size-4" />
                            </button>
                        @endif
                    </div>
                @endforeach

                @error('playerNames.*')
                    <p class="text-sm text-red-400">{{ $message }}</p>
                @enderror

                @if (count($playerNames) < 4)
                    <button
                        type="button"
                        wire:click="addPlayer"
                        class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-zinc-700 py-2 text-sm text-zinc-500 hover:border-zinc-500 hover:text-zinc-300 transition"
                    >
                        <flux:icon name="plus" class="size-4" />
                        {{ __('Add player') }}
                    </button>
                @endif

                <div class="flex items-center gap-3 pt-1">
                    <flux:input
                        wire:model="endScore"
                        type="number"
                        min="10"
                        max="999"
                        :label="__('Target score')"
                        class="w-24"
                    />
                    <flux:button type="submit" variant="primary" class="flex-1">
                        {{ __('Start game') }}
                    </flux:button>
                </div>
                @error('endScore')
                    <p class="text-sm text-red-400">{{ $message }}</p>
                @enderror
            </form>
        </div>

        {{-- Network game card --}}
        <div class="rounded-2xl border border-zinc-800 bg-zinc-900 p-6 flex flex-col">
            <div class="mb-4">
                <h2 class="mb-1 text-xl font-semibold">{{ __('Play online') }}</h2>
                <p class="text-sm text-zinc-400">{{ __('Each player joins on their own device. Real-time multiplayer with a team.') }}</p>
            </div>

            <ul class="mb-6 space-y-2 text-sm text-zinc-400">
                <li class="flex items-start gap-2"><flux:icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-green-500" />{{ __('Create a game and share the invite link') }}</li>
                <li class="flex items-start gap-2"><flux:icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-green-500" />{{ __('2–8 players, each on their own screen') }}</li>
                <li class="flex items-start gap-2"><flux:icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-green-500" />{{ __('Live updates via WebSockets') }}</li>
            </ul>

            <div class="mt-auto flex flex-col gap-2">
                <a href="{{ route('register') }}" class="flex items-center justify-center rounded-lg bg-zinc-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-600 transition">
                    {{ __('Create an account') }}
                </a>
                <a href="{{ route('login') }}" class="flex items-center justify-center rounded-lg border border-zinc-700 px-4 py-2.5 text-sm text-zinc-400 hover:border-zinc-500 hover:text-white transition">
                    {{ __('Sign in') }}
                </a>
            </div>
        </div>
    </section>

    {{-- How to play --}}
    <section class="mx-auto max-w-5xl px-6 pb-20">
        <h2 class="mb-8 text-center text-2xl font-semibold">{{ __('How it works') }}</h2>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['icon' => 'squares-2x2', 'title' => __('3×4 grid'), 'body' => __('Each player gets 12 face-down cards arranged in a 4-column grid. Flip 2 to start.')],
                ['icon' => 'arrow-path', 'title' => __('Take turns'), 'body' => __('Draw from the deck or take the top discard. Swap it into your grid or discard it and flip a hidden card.')],
                ['icon' => 'trophy', 'title' => __('End the round'), 'body' => __('When one player reveals all their cards, everyone else gets one final turn.')],
                ['icon' => 'chart-bar', 'title' => __('Score & win'), 'body' => __('Lowest score wins. Hit exactly the target score (default 100) and you win instantly!')],
            ] as $step)
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5">
                    <flux:icon :name="$step['icon']" class="mb-3 size-6 text-zinc-400" />
                    <h3 class="mb-1 font-semibold">{{ $step['title'] }}</h3>
                    <p class="text-sm text-zinc-400">{{ $step['body'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Scoring tiers --}}
        <div class="mt-10 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
            <h3 class="mb-4 font-semibold">{{ __('Scoring') }}</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => '-2, -1, 0', 'score' => __('Face value'), 'bg' => 'bg-blue-900/60 text-blue-200'],
                    ['label' => '1 – 4', 'score' => __('1 pt each'), 'bg' => 'bg-green-900/60 text-green-200'],
                    ['label' => '5 – 9', 'score' => __('5 pts each'), 'bg' => 'bg-yellow-900/60 text-yellow-200'],
                    ['label' => '10 – 12', 'score' => __('10 pts each'), 'bg' => 'bg-red-900/60 text-red-200'],
                ] as $tier)
                    <div class="rounded-lg {{ $tier['bg'] }} p-3 text-center">
                        <div class="text-lg font-bold">{{ $tier['label'] }}</div>
                        <div class="text-xs opacity-75">{{ $tier['score'] }}</div>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-sm text-zinc-400">
                {{ __('Score 70+ in a single round → your round score becomes −7 instead. End the round with a higher score than your opponents → your score doubles.') }}
            </p>
            <p class="mt-2 text-sm text-zinc-400">
                {{ __('Three matching face values in one column → those cards are removed from the game.') }}
            </p>
        </div>
    </section>
</div>
