<?php

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

        $this->roundScores = $this->game->roundScores()
            ->orderBy('round_number')
            ->get()
            ->groupBy('game_player_id')
            ->map(fn ($scores) => $scores->pluck('adjusted_score', 'round_number')->toArray())
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

        {{-- ═══════════════════════════ 2-PLAYER SPLIT SCREEN ═══════════════════════════ --}}
        @if (count($players) === 2)
            @php
                $p1 = $players[0];
                $p2 = $players[1];
                $p1CanSwap = $p1['is_current'] && $game->turn_phase === TurnPhase::Held;
                $p1CanFlip = $p1['is_current'] && $game->turn_phase === TurnPhase::Flip;
                $p2CanSwap = $p2['is_current'] && $game->turn_phase === TurnPhase::Held;
                $p2CanFlip = $p2['is_current'] && $game->turn_phase === TurnPhase::Flip;
            @endphp

            <div class="flex h-dvh flex-col overflow-hidden" style="--cw: min(calc((50dvw - 37px) / 8), calc((100dvh - 160px) / 9));">

                {{-- ── P2 half (rotated 180°) — DOM: [P1 opponent | P2 own] so P2 sees own on right ── --}}
                <div
                    x-data="{ showScores: false }"
                    @round-ended.window="showScores = true"
                    class="relative flex min-h-0 flex-1 flex-col border-b-2 {{ $p2['is_current'] ? 'border-accent' : 'border-zinc-200 dark:border-zinc-700' }}"
                    style="transform: rotate(180deg);"
                >
                    {{-- Score overlay (inherits rotation so P2 reads it normally) --}}
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
                        <span class="text-xs font-semibold">{{ $p2['name'] }}</span>
                        <div class="flex items-center gap-1">
                            <span class="text-xs font-bold text-zinc-500">{{ $p2['total_score'] }} pts</span>
                            <button @click="showScores = true" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" type="button">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </button>
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

                    <div class="flex min-h-0 flex-1 items-center justify-center gap-2 px-1.5 pb-1.5">
                        {{-- P1 cards (opponent, no interaction) --}}
                        <div class="flex flex-col items-center">
                            <p class="shrink-0 truncate pb-0.5 text-center text-[10px] text-zinc-400">{{ $p1['name'] }}</p>
                            <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                                @for ($row = 0; $row < 3; $row++)
                                    @for ($col = 0; $col < 4; $col++)
                                        @php $cell = $p1['cards'][$row][$col]; @endphp
                                        @include('games._card-cell', ['cell' => $cell, 'canSwap' => false, 'canFlip' => false])
                                    @endfor
                                @endfor
                            </div>
                        </div>

                        <div class="mx-0.5 w-px shrink-0 self-stretch bg-zinc-200 dark:bg-zinc-700"></div>

                        {{-- P2 own cards (interactive) --}}
                        <div class="flex flex-col items-center">
                            <p class="shrink-0 pb-0.5 text-center text-[10px] text-zinc-400">{{ __('Yours') }}</p>
                            <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                                @for ($row = 0; $row < 3; $row++)
                                    @for ($col = 0; $col < 4; $col++)
                                        @php $cell = $p2['cards'][$row][$col]; @endphp
                                        @include('games._card-cell', ['cell' => $cell, 'canSwap' => $p2CanSwap, 'canFlip' => $p2CanFlip])
                                    @endfor
                                @endfor
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── Middle deck strip ──────────────────────────────────────────────── --}}
                <div class="flex shrink-0 items-center justify-center gap-3 border-y border-zinc-300 bg-zinc-100 px-3 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                    @if ($game->status === GameStatus::Scoring)
                        <flux:badge color="yellow">{{ __('Final turns!') }}</flux:badge>
                    @endif

                    <div class="flex items-end gap-2">
                        {{-- Draw pile --}}
                        <div class="flex flex-col items-center gap-0.5">
                            <span class="text-[10px] text-zinc-400">{{ $this->drawPileCount }}</span>
                            @if ($game->turn_phase === TurnPhase::Draw)
                                <button wire:click="drawFromPile" class="flex cursor-pointer items-center justify-center overflow-hidden rounded border-2 border-transparent bg-slate-700 text-white transition hover:scale-105 hover:border-accent" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                    <flux:icon name="arrow-down-tray" class="size-3" />
                                </button>
                            @else
                                <div class="overflow-hidden rounded bg-slate-700/30" style="width: var(--cw); aspect-ratio: 2/3;"></div>
                            @endif
                            <span class="text-[10px] text-zinc-400">{{ __('Draw') }}</span>
                        </div>

                        @if ($this->discardTop !== null)
                            <div class="flex flex-col items-center gap-0.5">
                                <span class="text-[10px] text-zinc-400">{{ __('Discard') }}</span>
                                @if ($game->turn_phase === TurnPhase::Draw)
                                    <button wire:click="takeFromDiscard" class="flex cursor-pointer flex-col overflow-hidden rounded border-2 border-transparent font-bold transition hover:scale-105 hover:border-accent {{ \App\View\CardColor::fromValue($this->discardTop) }}" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                        <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="text-xs leading-none">{{ $this->discardTop }}</span></div>
                                        <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                        <div class="flex flex-1 items-center justify-center"><span class="text-xs leading-none">{{ $this->discardTop }}</span></div>
                                    </button>
                                @else
                                    <div class="flex flex-col overflow-hidden rounded font-bold opacity-40 {{ \App\View\CardColor::fromValue($this->discardTop) }}" style="width: var(--cw); aspect-ratio: 2/3;">
                                        <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="text-xs leading-none">{{ $this->discardTop }}</span></div>
                                        <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                        <div class="flex flex-1 items-center justify-center"><span class="text-xs leading-none">{{ $this->discardTop }}</span></div>
                                    </div>
                                @endif
                                <span class="text-[10px] text-zinc-400">{{ __('Take') }}</span>
                            </div>
                        @endif
                    </div>

                </div>

                {{-- ── P1 half (normal orientation, bottom) — DOM: [P2 opponent | P1 own] ── --}}
                <div
                    x-data="{ showScores: false }"
                    @round-ended.window="showScores = true"
                    class="relative flex min-h-0 flex-1 flex-col border-t-2 {{ $p1['is_current'] ? 'border-accent' : 'border-zinc-200 dark:border-zinc-700' }}"
                >
                    {{-- Score overlay (normal orientation — P1 reads it normally) --}}
                    <div x-show="showScores" x-transition class="absolute inset-0 z-20 flex flex-col overflow-auto bg-white/97 p-3 dark:bg-zinc-900/97">
                        <div class="mb-2 flex shrink-0 items-center justify-between">
                            <span class="text-sm font-semibold">{{ __('Round :n scores', ['n' => $game->current_round - 1]) }}</span>
                            <button @click="showScores = false" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" type="button">✕</button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-auto">
                            @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $game->current_round])
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 items-center justify-center gap-2 px-1.5 pt-1.5">
                        {{-- P2 cards (opponent, no interaction) --}}
                        <div class="flex flex-col items-center">
                            <p class="shrink-0 truncate pb-0.5 text-center text-[10px] text-zinc-400">{{ $p2['name'] }}</p>
                            <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                                @for ($row = 0; $row < 3; $row++)
                                    @for ($col = 0; $col < 4; $col++)
                                        @php $cell = $p2['cards'][$row][$col]; @endphp
                                        @include('games._card-cell', ['cell' => $cell, 'canSwap' => false, 'canFlip' => false])
                                    @endfor
                                @endfor
                            </div>
                        </div>

                        <div class="mx-0.5 w-px shrink-0 self-stretch bg-zinc-200 dark:bg-zinc-700"></div>

                        {{-- P1 own cards (interactive) --}}
                        <div class="flex flex-col items-center">
                            <p class="shrink-0 pb-0.5 text-center text-[10px] text-zinc-400">{{ __('Yours') }}</p>
                            <div class="grid gap-0.5" style="grid-template-columns: repeat(4, var(--cw));">
                                @for ($row = 0; $row < 3; $row++)
                                    @for ($col = 0; $col < 4; $col++)
                                        @php $cell = $p1['cards'][$row][$col]; @endphp
                                        @include('games._card-cell', ['cell' => $cell, 'canSwap' => $p1CanSwap, 'canFlip' => $p1CanFlip])
                                    @endfor
                                @endfor
                            </div>
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

                    <div class="flex shrink-0 items-center justify-between px-2 py-0.5">
                        <span class="text-xs font-semibold">{{ $p1['name'] }}</span>
                        <div class="flex items-center gap-1">
                            <span class="text-xs font-bold text-zinc-500">{{ $p1['total_score'] }} pts</span>
                            <button @click="showScores = true" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" type="button">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </button>
                        </div>
                    </div>
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
                            <button wire:click="drawFromPile" class="flex cursor-pointer items-center justify-center overflow-hidden rounded-lg border-2 border-transparent bg-slate-700 font-bold text-white transition hover:scale-105 hover:border-accent" style="width: var(--cw); aspect-ratio: 2/3;" type="button">
                                <flux:icon name="arrow-down-tray" class="size-5" />
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

    {{-- ═══════════════════════════════ FINISHED ═══════════════════════════════ --}}
    @else
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 text-center">
                @php $winners = array_filter($players, fn ($p) => $p['is_winner']); @endphp
                @if (count($winners) === 1)
                    <flux:heading size="xl">🎉 {{ reset($winners)['name'] }} {{ __('wins!') }}</flux:heading>
                @elseif (count($winners) > 1)
                    <flux:heading size="xl">🎉 {{ __('It\'s a tie!') }}</flux:heading>
                    <flux:text>{{ implode(' & ', array_column($winners, 'name')) }}</flux:text>
                @endif
            </div>

            <div class="mb-8 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                @foreach (collect($players)->sortBy('total_score') as $player)
                    <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 last:border-0 dark:border-zinc-800">
                        <div class="flex items-center gap-3">
                            @if ($player['is_winner'])
                                <flux:icon name="trophy" class="size-5 text-yellow-500" />
                            @endif
                            <span class="font-medium">{{ $player['name'] }}</span>
                        </div>
                        <span class="text-lg font-bold">{{ $player['total_score'] }} pts</span>
                    </div>
                @endforeach
            </div>

            <flux:button :href="route('home')" class="w-full" variant="primary">
                {{ __('Play again') }}
            </flux:button>
        </div>
    @endif

</div>
