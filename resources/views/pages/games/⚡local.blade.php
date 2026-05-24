<?php

use App\Actions\Games\StartLocalGuestGame;
use App\Actions\Games\TakeTurn;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\TurnPhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameEngine;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Hej-Joe')] #[Layout('layouts.guest')] class extends Component {
    public Game $game;

    /** @var array<int, array<string, mixed>> */
    public array $players = [];

    /** @var array<int, array<int, int>> */
    public array $roundScores = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     * Keyed by game_player_id → round_number → [raw, adjusted, doubled, ended_round]
     */
    public array $roundScoreDetails = [];

    public int $lastSeenRound = 0;

    public function mount(Game $game): void
    {
        abort_if($game->mode !== GameMode::Local, 404);
        abort_if($game->status === GameStatus::Waiting, 404);

        $this->game = $game;
        $this->lastSeenRound = $game->current_round;
        $this->loadState();
    }

    public function drawFromPile(TakeTurn $takeTurn): void
    {
        $takeTurn->drawFromPile($this->game->fresh(), $this->activePlayer());
        $this->game->refresh();
        $this->loadState();
    }

    public function takeFromDiscard(TakeTurn $takeTurn): void
    {
        $takeTurn->takeFromDiscard($this->game->fresh(), $this->activePlayer());
        $this->game->refresh();
        $this->loadState();
    }

    public function placeCard(int $position, TakeTurn $takeTurn): void
    {
        $takeTurn->placeCard($this->game->fresh(), $this->activePlayer(), $position);
        $this->game->refresh();
        $this->loadState();
    }

    public function discardHeld(TakeTurn $takeTurn): void
    {
        $takeTurn->discardHeld($this->game->fresh(), $this->activePlayer());
        $this->game->refresh();
        $this->loadState();
    }

    public function flipCard(int $position, TakeTurn $takeTurn): void
    {
        $takeTurn->flipCard($this->game->fresh(), $this->activePlayer(), $position);
        $this->game->refresh();
        $this->loadState();
    }

    public function confirmReady(int $playerId, TakeTurn $takeTurn): void
    {
        $player = $this->game->players()->findOrFail($playerId);
        $takeTurn->confirmReady($this->game->fresh(), $player);
        $this->game->refresh();
        $this->loadState();
    }

    public function rematch(StartLocalGuestGame $action): void
    {
        $playerNames = $this->game->players()->orderBy('seat')->pluck('guest_name')->toArray();
        $newGame = $action($playerNames, $this->game->end_score);
        $this->redirectRoute('games.local', ['game' => $newGame->invite_code], navigate: true);
    }

    private function activePlayer(): GamePlayer
    {
        return $this->game->currentPlayer;
    }

    #[Computed]
    public function currentPlayerName(): string
    {
        foreach ($this->players as $player) {
            if ($player['is_current']) {
                return $player['name'];
            }
        }

        return '';
    }

    #[Computed]
    public function discardTop(): ?int
    {
        return $this->game->discardTop();
    }

    #[Computed]
    public function drawPileCount(): int
    {
        return count($this->game->draw_pile ?? []);
    }

    #[Computed]
    public function heldCard(): ?int
    {
        return $this->game->held_card_value;
    }

    public function loadState(): void
    {
        $players = $this->game->players()->with('cards')->get();
        $engine = app(GameEngine::class);

        $this->players = $players->map(function (GamePlayer $player) use ($engine) {
            return [
                'id' => $player->id,
                'name' => $player->displayName(),
                'seat' => $player->seat,
                'total_score' => $player->total_score,
                'is_current' => $player->id === $this->game->current_player_id,
                'has_finished' => $player->has_finished_revealing,
                'is_winner' => $player->is_winner,
                'cards' => $this->buildCardGrid($player, $engine),
            ];
        })->toArray();

        $roundScoreRecords = $this->game->roundScores()
            ->orderBy('round_number')
            ->get()
            ->groupBy('game_player_id');

        $this->roundScores = $roundScoreRecords
            ->map(fn ($scores) => $scores->pluck('adjusted_score', 'round_number')->toArray())
            ->toArray();

        $this->roundScoreDetails = $roundScoreRecords
            ->map(fn ($scores) => $scores->mapWithKeys(fn ($s) => [
                $s->round_number => [
                    'raw' => $s->raw_score,
                    'adjusted' => $s->adjusted_score,
                    'doubled' => $s->is_doubled,
                    'ended_round' => $s->triggered_round_end,
                ],
            ])->toArray())
            ->toArray();

        $currentRound = $this->game->current_round;

        if ($currentRound > $this->lastSeenRound) {
            $this->lastSeenRound = $currentRound;
            $this->dispatch('round-ended');
        }
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function buildCardGrid(GamePlayer $player, GameEngine $engine): array
    {
        $cards = $player->cards->keyBy('position');
        $grid = [];

        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 4; $col++) {
                $position = $col * 3 + $row;
                $card = $cards->get($position);

                $grid[$row][$col] = [
                    'position' => $position,
                    'value' => $card?->value,
                    'is_face_up' => $card ? $card->is_face_up : false,
                    'exists' => $card !== null,
                ];
            }
        }

        return $grid;
    }
}; ?>

{{-- Cards fill their grid cells; values shown in both halves so each player can read from their side --}}
<div class="h-dvh w-full overflow-hidden bg-zinc-50 dark:bg-zinc-950">

    @if (in_array($game->status->value, [GameStatus::Active->value, GameStatus::Scoring->value]))

        {{-- ═══════════════════════════ 2-PLAYER SIDE-BY-SIDE ═══════════════════════════ --}}
        {{-- P1 left (normal), deck strip center, P2 right (rotated 180° for face-to-face) --}}
        @if (count($players) === 2)
            @php
                $p1 = $players[0];
                $p2 = $players[1];
                $p1CanSwap = $p1['is_current'] && $game->turn_phase === TurnPhase::Held;
                $p1CanFlip = $p1['is_current'] && $game->turn_phase === TurnPhase::Flip;
                $p2CanSwap = $p2['is_current'] && $game->turn_phase === TurnPhase::Held;
                $p2CanFlip = $p2['is_current'] && $game->turn_phase === TurnPhase::Flip;
            @endphp

            <div class="flex h-dvh overflow-hidden" style="--cw: min(calc((100dvw - 56px) / 9), calc((100dvh - 80px) / 4.5));">

                {{-- ── P1 half (left, normal orientation) ── --}}
                <div
                    x-data="{ showScores: false }"
                    @round-ended.window="showScores = true"
                    class="relative flex min-h-0 flex-1 flex-col overflow-hidden border-r-2 {{ $p1['is_current'] ? 'border-accent' : 'border-zinc-200 dark:border-zinc-700' }}"
                >
                    <div x-show="showScores" x-transition class="absolute inset-0 z-20 flex flex-col overflow-auto bg-white/97 p-3 dark:bg-zinc-900/97">
                        <div class="mb-2 flex shrink-0 items-center justify-between">
                            <span class="text-sm font-semibold">{{ __('Round :n scores', ['n' => $game->current_round - 1]) }}</span>
                            <button @click="showScores = false" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" type="button">✕</button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-auto">
                            @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $game->current_round])
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-between px-2 py-0.5">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs font-semibold">{{ $p1['name'] }}</span>
                            @if ($p1['is_current'])
                                <flux:badge color="lime" size="sm">{{ __('Your turn') }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-xs font-bold text-zinc-500">{{ $p1['total_score'] }} pts</span>
                            <button @click="showScores = true" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" type="button">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 items-center justify-center">
                        <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $p1['cards'][$row][$col]; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => $p1CanSwap, 'canFlip' => $p1CanFlip])
                                @endfor
                            @endfor
                        </div>
                    </div>

                    @if ($p1['is_current'] && $this->heldCard !== null)
                        <div class="flex shrink-0 items-center justify-center gap-2 py-0.5">
                            @include('games._held-card', ['value' => $this->heldCard])
                            @if ($game->turn_phase === TurnPhase::Held)
                                <flux:button wire:click="discardHeld" variant="ghost" size="sm" icon="arrow-up-tray">{{ __('Discard') }}</flux:button>
                            @endif
                        </div>
                    @endif
                    @if ($p1['is_current'] && $game->turn_phase === TurnPhase::Flip)
                        <p class="shrink-0 pb-0.5 text-center text-xs text-yellow-600 dark:text-yellow-400">{{ __('Click a hidden card to reveal') }}</p>
                    @endif
                </div>

                {{-- ── Center deck strip (vertical) ── --}}
                <div class="flex shrink-0 flex-col items-center justify-center gap-2 border-x border-zinc-300 bg-zinc-100 px-2 py-3 dark:border-zinc-700 dark:bg-zinc-900" style="width: calc(var(--cw) + 16px);">
                    @if ($game->status === GameStatus::Scoring)
                        <flux:badge color="yellow">{{ __('Final!') }}</flux:badge>
                    @endif

                    <div class="flex flex-col items-center gap-0.5">
                        <span class="text-[10px] text-zinc-400">{{ $this->drawPileCount }}</span>
                        @if ($game->turn_phase === TurnPhase::Draw)
                            <button wire:click="drawFromPile" class="cursor-pointer overflow-hidden rounded border-2 border-transparent transition hover:scale-105 hover:border-accent" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                <x-card-back class="h-full w-full" />
                            </button>
                        @else
                            <div class="overflow-hidden rounded opacity-40" style="width: var(--cw); aspect-ratio: 2/3;">
                                <x-card-back class="h-full w-full" />
                            </div>
                        @endif
                        <span class="text-[10px] text-zinc-400">{{ __('Draw') }}</span>
                    </div>

                    @if ($this->discardTop !== null)
                        <div class="flex flex-col items-center gap-0.5">
                            <span class="text-[10px] text-zinc-400">{{ __('Discard') }}</span>
                            @if ($game->turn_phase === TurnPhase::Draw)
                                <button wire:click="takeFromDiscard" class="flex cursor-pointer flex-col overflow-hidden rounded border-2 border-transparent font-bold transition hover:scale-105 hover:border-accent {{ \App\View\CardColor::fromValue($this->discardTop) }}" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                    <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="leading-none" style="font-size: clamp(7px, 2.5dvh, 16px);">{{ $this->discardTop }}</span></div>
                                    <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                    <div class="flex flex-1 items-center justify-center"><span class="leading-none" style="font-size: clamp(7px, 2.5dvh, 16px);">{{ $this->discardTop }}</span></div>
                                </button>
                            @else
                                <div class="flex flex-col overflow-hidden rounded font-bold opacity-40 {{ \App\View\CardColor::fromValue($this->discardTop) }}" style="width: var(--cw); aspect-ratio: 2/3;">
                                    <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="leading-none" style="font-size: clamp(7px, 2.5dvh, 16px);">{{ $this->discardTop }}</span></div>
                                    <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                    <div class="flex flex-1 items-center justify-center"><span class="leading-none" style="font-size: clamp(7px, 2.5dvh, 16px);">{{ $this->discardTop }}</span></div>
                                </div>
                            @endif
                            <span class="text-[10px] text-zinc-400">{{ __('Take') }}</span>
                        </div>
                    @endif
                </div>

                {{-- ── P2 half (right, rotated 180° for face-to-face play) ── --}}
                <div
                    x-data="{ showScores: false }"
                    @round-ended.window="showScores = true"
                    class="relative flex min-h-0 flex-1 flex-col overflow-hidden border-l-2 {{ $p2['is_current'] ? 'border-accent' : 'border-zinc-200 dark:border-zinc-700' }}"
                    style="transform: rotate(180deg);"
                >
                    <div x-show="showScores" x-transition class="absolute inset-0 z-20 flex flex-col overflow-auto bg-white/97 p-3 dark:bg-zinc-900/97">
                        <div class="mb-2 flex shrink-0 items-center justify-between">
                            <span class="text-sm font-semibold">{{ __('Round :n scores', ['n' => $game->current_round - 1]) }}</span>
                            <button @click="showScores = false" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" type="button">✕</button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-auto">
                            @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $game->current_round])
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-between px-2 py-0.5">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs font-semibold">{{ $p2['name'] }}</span>
                            @if ($p2['is_current'])
                                <flux:badge color="lime" size="sm">{{ __('Your turn') }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-xs font-bold text-zinc-500">{{ $p2['total_score'] }} pts</span>
                            <button @click="showScores = true" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" type="button">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 items-center justify-center">
                        <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $p2['cards'][$row][$col]; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => $p2CanSwap, 'canFlip' => $p2CanFlip])
                                @endfor
                            @endfor
                        </div>
                    </div>

                    @if ($p2['is_current'] && $this->heldCard !== null)
                        <div class="flex shrink-0 items-center justify-center gap-2 py-0.5">
                            @include('games._held-card', ['value' => $this->heldCard])
                            @if ($game->turn_phase === TurnPhase::Held)
                                <flux:button wire:click="discardHeld" variant="ghost" size="sm" icon="arrow-up-tray">{{ __('Discard') }}</flux:button>
                            @endif
                        </div>
                    @endif
                    @if ($p2['is_current'] && $game->turn_phase === TurnPhase::Flip)
                        <p class="shrink-0 pb-0.5 text-center text-xs text-yellow-600 dark:text-yellow-400">{{ __('Click a hidden card to reveal') }}</p>
                    @endif
                </div>
            </div>

        {{-- ═════════════════════════ 3-4 PLAYER LAYOUT ══════════════════════════ --}}
        @else
            @php
                $activePl = collect($players)->firstWhere('is_current', true);
                $otherPls = collect($players)->filter(fn ($p) => ! $p['is_current'])->values()->all();
                $canSwap = $game->turn_phase === TurnPhase::Held;
                $canFlip = $game->turn_phase === TurnPhase::Flip;
            @endphp

            <div class="flex h-dvh flex-col overflow-hidden p-2" style="--cw: min(calc((100dvw - 50px) / 4), calc((200dvh - 600px) / 9));">
                {{-- Top bar --}}
                <div class="mb-2 flex shrink-0 items-center justify-between">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('home') }}" class="text-sm font-semibold text-zinc-500 transition hover:text-zinc-900 dark:hover:text-white">Hej-Joe</a>
                        <flux:badge color="zinc">{{ __('Round :n', ['n' => $game->current_round]) }}</flux:badge>
                        @if ($game->status === GameStatus::Scoring)
                            <flux:badge color="yellow">{{ __('Final turns!') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="text-xs font-medium text-green-600 dark:text-green-400">{{ $this->currentPlayerName }}'s turn</flux:text>
                </div>

                {{-- Other players (compact, fixed height) --}}
                @if (! empty($otherPls))
                    <div
                        class="mb-2 grid shrink-0 gap-2"
                        style="grid-template-columns: repeat({{ count($otherPls) }}, minmax(0, 1fr)); height: clamp(80px, 25dvh, 140px);"
                    >
                        @foreach ($otherPls as $player)
                            <div class="flex flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900" style="--cw: calc((2 * clamp(80px, 25dvh, 140px) - 36px) / 9);">
                                <div class="mb-0.5 flex shrink-0 items-center justify-between">
                                    <span class="truncate text-[10px] font-semibold">{{ $player['name'] }}</span>
                                    <span class="shrink-0 text-[10px] font-bold">{{ $player['total_score'] }}</span>
                                </div>
                                <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                                    @for ($row = 0; $row < 3; $row++)
                                        @for ($col = 0; $col < 4; $col++)
                                            @php $cell = $player['cards'][$row][$col]; @endphp
                                            @include('games._card-cell', ['cell' => $cell, 'canSwap' => false, 'canFlip' => false])
                                        @endfor
                                    @endfor
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Deck strip --}}
                <div class="mb-2 flex shrink-0 items-center justify-center gap-4">
                    @if ($game->turn_phase === TurnPhase::Draw)
                        <div class="flex flex-col items-center gap-1">
                            <flux:text class="text-xs text-zinc-500">{{ $this->drawPileCount }}</flux:text>
                            <button wire:click="drawFromPile" class="cursor-pointer overflow-hidden rounded-lg border-2 border-transparent transition hover:scale-105 hover:border-accent" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                <x-card-back class="h-full w-full" />
                            </button>
                            <flux:text class="text-xs">{{ __('Draw') }}</flux:text>
                        </div>
                        @if ($this->discardTop !== null)
                            <div class="flex flex-col items-center gap-1">
                                <flux:text class="text-xs text-zinc-500">{{ __('Discard') }}</flux:text>
                                <button wire:click="takeFromDiscard" class="flex cursor-pointer flex-col overflow-hidden rounded-lg border-2 border-transparent font-bold transition hover:scale-105 hover:border-accent {{ \App\View\CardColor::fromValue($this->discardTop) }}" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                    <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="text-sm leading-none">{{ $this->discardTop }}</span></div>
                                    <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                    <div class="flex flex-1 items-center justify-center"><span class="text-sm leading-none">{{ $this->discardTop }}</span></div>
                                </button>
                                <flux:text class="text-xs">{{ __('Take') }}</flux:text>
                            </div>
                        @endif
                    @endif

                    @if ($this->heldCard !== null)
                        <div class="flex items-center gap-2">
                            @include('games._held-card', ['value' => $this->heldCard])
                            @if ($game->turn_phase === TurnPhase::Held)
                                <div class="flex flex-col items-center gap-0.5">
                                    <flux:button wire:click="discardHeld" variant="ghost" size="sm" icon="arrow-up-tray">{{ __('Discard') }}</flux:button>
                                    <flux:text class="text-xs text-zinc-500">{{ __('then flip a hidden card') }}</flux:text>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($game->turn_phase === TurnPhase::Flip)
                        <flux:text class="text-sm text-yellow-600 dark:text-yellow-400">{{ __('Click one of :name\'s hidden cards', ['name' => $this->currentPlayerName]) }}</flux:text>
                    @endif
                </div>

                {{-- Active player's grid (fills remaining height) --}}
                @if ($activePl)
                    <div
                        x-data="{ showScores: false }"
                        @round-ended.window="showScores = true"
                        class="relative flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border-2 border-accent bg-white p-2 dark:bg-zinc-900"
                    >
                        {{-- Score overlay --}}
                        <div x-show="showScores" x-transition class="absolute inset-0 z-20 flex flex-col overflow-auto rounded-xl bg-white/97 p-3 dark:bg-zinc-900/97">
                            <div class="mb-2 flex shrink-0 items-center justify-between">
                                <span class="text-sm font-semibold">{{ __('Round :n scores', ['n' => $game->current_round - 1]) }}</span>
                                <button @click="showScores = false" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" type="button">✕</button>
                            </div>
                            <div class="min-h-0 flex-1 overflow-auto">
                                @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $game->current_round])
                            </div>
                        </div>

                        <div class="mb-1 flex shrink-0 items-center justify-between px-1">
                            <span class="text-sm font-semibold">{{ $activePl['name'] }}</span>
                            <div class="flex items-center gap-1">
                                <span class="text-sm font-bold">{{ $activePl['total_score'] }} pts</span>
                                <button @click="showScores = true" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" type="button">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="mx-auto grid gap-1" style="grid-template-columns: repeat(4, var(--cw));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $activePl['cards'][$row][$col]; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => $canSwap, 'canFlip' => $canFlip])
                                @endfor
                            @endfor
                        </div>
                        @if (! empty($roundScores[$activePl['id']] ?? []))
                            <div class="mt-1 flex shrink-0 flex-wrap gap-1 px-1">
                                @foreach ($roundScores[$activePl['id']] as $round => $score)
                                    <span class="rounded bg-zinc-100 px-1 text-xs text-zinc-500 dark:bg-zinc-800">{{ $score > 0 ? '+' : '' }}{{ $score }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

    {{-- ═══════════════════════════════ REVIEWING ═══════════════════════════════ --}}
    {{-- Round is over; cards stay visible until every player clicks Ready       --}}
    @elseif ($game->status === GameStatus::Reviewing)
        @php
            $readyIds   = $game->ready_player_ids ?? [];
            $lastRound  = $game->current_round;
            $engine     = app(\App\Services\GameEngine::class);
            $gameOver   = collect($players)->contains('is_winner', true);
            $winners    = collect($players)->filter(fn ($p) => $p['is_winner'])->values();
        @endphp

        @if (count($players) === 2)
            @php
                $p1 = $players[0];
                $p2 = $players[1];
            @endphp

            {{-- --cw sized to leave ~35% of height for scores + button below the card grid --}}
            <div class="flex h-dvh overflow-hidden" style="--cw: min(calc((50dvw - 20px) / 4), calc(62dvh / 4.5));">

                {{-- P1 left --}}
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden border-r-2 border-zinc-200 dark:border-zinc-700">
                    @if ($gameOver)
                        <div class="shrink-0 bg-yellow-50 px-2 py-1 text-center text-xs font-semibold text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                            @if ($winners->count() === 1)
                                🎉 {{ $winners->first()['name'] }} {{ __('wins!') }}
                            @else
                                🎉 {{ __("It's a tie!") }}
                            @endif
                        </div>
                    @endif
                    <div class="flex shrink-0 items-center justify-between px-2 py-1">
                        <span class="text-xs font-semibold">{{ $p1['name'] }}</span>
                        <span class="text-xs font-bold text-zinc-500">{{ $p1['total_score'] }} pts total</span>
                    </div>

                    <div class="flex min-h-0 flex-1 items-center justify-center px-1">
                        <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $p1['cards'][$row][$col]; $cell['is_face_up'] = $cell['exists']; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => false, 'canFlip' => false])
                                @endfor
                            @endfor
                        </div>
                    </div>

                    @php $d1 = $roundScoreDetails[$p1['id']][$lastRound] ?? null; @endphp
                    <div class="shrink-0 overflow-auto px-2 py-1 text-xs">
                        @if ($d1)
                            <p class="mb-1 text-zinc-500">
                                {{ __('Round :n', ['n' => $lastRound]) }}:
                                <strong>{{ $d1['raw'] }}</strong>
                                @if ($d1['raw'] >= 70) <span class="text-green-600">→ −7</span> @endif
                                @if ($d1['doubled']) <span class="text-red-500">→ ×2</span> @endif
                                = <strong>{{ $d1['adjusted'] > 0 ? '+' : '' }}{{ $d1['adjusted'] }}</strong>
                            </p>
                        @endif
                        @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $lastRound + 1])
                    </div>

                    <div class="shrink-0 px-2 pb-2">
                        @if (in_array($p1['id'], $readyIds))
                            <flux:button disabled size="sm" class="w-full">✓ {{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                        @else
                            <flux:button wire:click="confirmReady({{ $p1['id'] }})" variant="primary" size="sm" class="w-full">{{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                        @endif
                    </div>
                </div>

                {{-- P2 right (rotated 180° — score table and Ready button read correctly from P2's side) --}}
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden border-l-2 border-zinc-200 dark:border-zinc-700" style="transform: rotate(180deg);">
                    @if ($gameOver)
                        <div class="shrink-0 bg-yellow-50 px-2 py-1 text-center text-xs font-semibold text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                            @if ($winners->count() === 1)
                                🎉 {{ $winners->first()['name'] }} {{ __('wins!') }}
                            @else
                                🎉 {{ __("It's a tie!") }}
                            @endif
                        </div>
                    @endif
                    <div class="flex shrink-0 items-center justify-between px-2 py-1">
                        <span class="text-xs font-semibold">{{ $p2['name'] }}</span>
                        <span class="text-xs font-bold text-zinc-500">{{ $p2['total_score'] }} pts total</span>
                    </div>

                    <div class="flex min-h-0 flex-1 items-center justify-center px-1">
                        <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $p2['cards'][$row][$col]; $cell['is_face_up'] = $cell['exists']; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => false, 'canFlip' => false])
                                @endfor
                            @endfor
                        </div>
                    </div>

                    @php $d2 = $roundScoreDetails[$p2['id']][$lastRound] ?? null; @endphp
                    <div class="shrink-0 overflow-auto px-2 py-1 text-xs">
                        @if ($d2)
                            <p class="mb-1 text-zinc-500">
                                {{ __('Round :n', ['n' => $lastRound]) }}:
                                <strong>{{ $d2['raw'] }}</strong>
                                @if ($d2['raw'] >= 70) <span class="text-green-600">→ −7</span> @endif
                                @if ($d2['doubled']) <span class="text-red-500">→ ×2</span> @endif
                                = <strong>{{ $d2['adjusted'] > 0 ? '+' : '' }}{{ $d2['adjusted'] }}</strong>
                            </p>
                        @endif
                        @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $lastRound + 1])
                    </div>

                    <div class="shrink-0 px-2 pb-2">
                        @if (in_array($p2['id'], $readyIds))
                            <flux:button disabled size="sm" class="w-full">✓ {{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                        @else
                            <flux:button wire:click="confirmReady({{ $p2['id'] }})" variant="primary" size="sm" class="w-full">{{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                        @endif
                    </div>
                </div>
            </div>

        @else
            {{-- 3-4 player review --}}
            <div class="mx-auto max-w-3xl space-y-4 overflow-y-auto px-4 py-6">
                @if ($gameOver)
                    <div class="rounded-xl bg-yellow-50 px-4 py-3 text-center dark:bg-yellow-900/30">
                        @if ($winners->count() === 1)
                            <flux:heading size="lg">🎉 {{ $winners->first()['name'] }} {{ __('wins!') }}</flux:heading>
                        @else
                            <flux:heading size="lg">🎉 {{ __("It's a tie!") }}</flux:heading>
                        @endif
                    </div>
                @endif
                <flux:heading size="lg" class="text-center">{{ __('Round :n complete', ['n' => $lastRound]) }}</flux:heading>

                <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                    @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $lastRound + 1])
                </div>

                @foreach ($players as $player)
                    @php $detail = $roundScoreDetails[$player['id']][$lastRound] ?? null; @endphp
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="mb-2 flex items-center gap-2">
                            <span class="font-semibold">{{ $player['name'] }}</span>
                            <span class="ml-auto text-sm font-bold">{{ $player['total_score'] }} pts</span>
                        </div>
                        <div class="mb-3 grid gap-1" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $player['cards'][$row][$col]; $cell['is_face_up'] = $cell['exists']; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => false, 'canFlip' => false])
                                @endfor
                            @endfor
                        </div>
                        @if ($detail)
                            <p class="mb-2 text-xs text-zinc-500">
                                {{ __('Round :n', ['n' => $lastRound]) }}:
                                <strong>{{ $detail['raw'] }}</strong>
                                @if ($detail['raw'] >= 70) <span class="text-green-600">→ −7</span> @endif
                                @if ($detail['doubled']) <span class="text-red-500">→ ×2</span> @endif
                                = <strong>{{ $detail['adjusted'] > 0 ? '+' : '' }}{{ $detail['adjusted'] }}</strong>
                            </p>
                        @endif
                        @if (in_array($player['id'], $readyIds))
                            <flux:button disabled size="sm" class="w-full">✓ {{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                        @else
                            <flux:button wire:click="confirmReady({{ $player['id'] }})" variant="primary" size="sm" class="w-full">{{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

    {{-- ═══════════════════════════════ FINISHED ═══════════════════════════════ --}}
    @else
        @php
            $winners = array_filter($players, fn ($p) => $p['is_winner']);
            $engine  = app(\App\Services\GameEngine::class);
        @endphp
        <div class="mx-auto max-w-3xl space-y-6 overflow-y-auto px-4 py-8">

            {{-- Winner banner --}}
            <div class="text-center">
                @if (count($winners) === 1)
                    <flux:heading size="xl">🎉 {{ reset($winners)['name'] }} {{ __('wins!') }}</flux:heading>
                @elseif (count($winners) > 1)
                    <flux:heading size="xl">🎉 {{ __("It's a tie!") }}</flux:heading>
                    <flux:text>{{ implode(' & ', array_column($winners, 'name')) }}</flux:text>
                @endif
            </div>

            {{-- Score table (all rounds) --}}
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $game->current_round + 1])
            </div>

            {{-- Per-player final card grid + score breakdown --}}
            @foreach (collect($players)->sortBy('total_score') as $player)
                @php
                    $lastRound = $game->current_round;
                    $detail    = $roundScoreDetails[$player['id']][$lastRound] ?? null;
                @endphp
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        @if ($player['is_winner'])
                            <flux:icon name="trophy" class="size-4 text-yellow-500" />
                        @endif
                        <span class="font-semibold">{{ $player['name'] }}</span>
                        <span class="ml-auto font-bold">{{ $player['total_score'] }} pts total</span>
                    </div>

                    {{-- Card grid — all cards revealed so score can be verified --}}
                    <div class="mb-3 grid gap-1" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
                        @for ($row = 0; $row < 3; $row++)
                            @for ($col = 0; $col < 4; $col++)
                                @php
                                    $cell = $player['cards'][$row][$col];
                                    $cell['is_face_up'] = $cell['exists'];
                                    $scored = $cell['exists'] ? $engine->scoreCard($cell['value']) : null;
                                @endphp
                                @if ($cell['exists'])
                                    <div class="flex flex-col overflow-hidden rounded font-bold {{ \App\View\CardColor::fromValue($cell['value']) }}" style="aspect-ratio: 2/3;">
                                        <div class="flex flex-1 items-center justify-center text-xs leading-none" style="transform: rotate(180deg);">{{ $cell['value'] }}</div>
                                        <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                        <div class="flex flex-1 items-center justify-center text-xs leading-none">{{ $cell['value'] }}</div>
                                        <div class="shrink-0 bg-black/10 py-0.5 text-center text-[10px] font-normal leading-none">
                                            {{ $scored > 0 ? '+' : '' }}{{ $scored }}
                                        </div>
                                    </div>
                                @else
                                    <div class="rounded border border-dashed border-zinc-200 dark:border-zinc-700" style="aspect-ratio: 2/3;"></div>
                                @endif
                            @endfor
                        @endfor
                    </div>

                    {{-- Score breakdown for the last round --}}
                    @if ($detail)
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                            <span>{{ __('Last round:') }}</span>
                            <span>{{ __('Raw') }} <strong>{{ $detail['raw'] }} pts</strong></span>
                            @if ($detail['raw'] >= 70)
                                <flux:badge color="green">{{ __('≥70 → −7') }}</flux:badge>
                            @endif
                            @if ($detail['doubled'])
                                <flux:badge color="red">{{ __('Ended round with highest score → ×2') }}</flux:badge>
                            @endif
                            @if ($detail['adjusted'] !== $detail['raw'])
                                <span>→ {{ __('Adjusted') }} <strong>{{ $detail['adjusted'] > 0 ? '+' : '' }}{{ $detail['adjusted'] }} pts</strong></span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Score rule reminder --}}
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">
                <span class="mr-2 font-medium text-zinc-700 dark:text-zinc-300">{{ __('Scoring:') }}</span>
                <span class="mr-3">{{ __('Each card scores its face value.') }}</span>
                <span class="mr-3">{{ __('Round ≥ 70 pts → −7.') }}</span>
                <span>{{ __('Ended round with highest score → ×2.') }}</span>
            </div>

            <div class="flex gap-3">
                <flux:button wire:click="rematch" class="flex-1" variant="primary">
                    {{ __('Play again') }}
                </flux:button>
                <flux:button :href="route('home')" class="flex-1">
                    {{ __('Back to menu') }}
                </flux:button>
            </div>
        </div>
    @endif

</div>
