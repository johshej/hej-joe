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

    public function mount(Game $game): void
    {
        abort_if($game->mode !== GameMode::Local, 404);
        abort_if($game->status === GameStatus::Waiting, 404);

        $this->game = $game;
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

    public function discardAndFlip(int $position, TakeTurn $takeTurn): void
    {
        $takeTurn->discardAndFlip($this->game->fresh(), $this->activePlayer(), $position);
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

<div class="min-h-screen w-full bg-zinc-50 dark:bg-zinc-950">
    {{-- ============================= GAME BOARD ============================= --}}
    @if (in_array($game->status->value, [GameStatus::Active->value, GameStatus::Scoring->value]))
        <div class="p-2 sm:p-4">
            {{-- Top bar --}}
            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="{{ route('home') }}" class="text-sm font-semibold text-zinc-500 hover:text-zinc-900 dark:hover:text-white transition">Hej-Joe</a>
                    <flux:badge color="zinc">{{ __('Round :n', ['n' => $game->current_round]) }}</flux:badge>
                    <flux:badge color="zinc">{{ __('Target: :score pts', ['score' => $game->end_score]) }}</flux:badge>
                    @if ($game->status === GameStatus::Scoring)
                        <flux:badge color="yellow">{{ __('Final turns!') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="text-sm font-medium text-green-600 dark:text-green-400">
                    {{ __(':name\'s turn', ['name' => $this->currentPlayerName]) }}
                </flux:text>
            </div>

            {{-- Player grids --}}
            <div @class([
                'grid gap-3',
                'grid-cols-2' => count($players) <= 2,
                'grid-cols-2 sm:grid-cols-3' => count($players) === 3,
                'grid-cols-2 sm:grid-cols-4' => count($players) >= 4,
            ])>
                @foreach ($players as $player)
                    @php $isCurrentPlayer = $player['is_current']; @endphp

                    <div @class([
                        'rounded-xl border bg-white p-2 dark:bg-zinc-900 transition',
                        'border-accent ring-2 ring-accent/50' => $isCurrentPlayer,
                        'border-zinc-200 dark:border-zinc-700' => ! $isCurrentPlayer,
                    ])>
                        <div class="mb-2 flex items-center justify-between px-1">
                            <span class="text-sm font-semibold">{{ $player['name'] }}</span>
                            <span class="text-sm font-bold">{{ $player['total_score'] }} pts</span>
                        </div>

                        <div class="grid grid-cols-4 gap-1">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php
                                        $cell = $player['cards'][$row][$col];
                                        $isInteractive = $isCurrentPlayer && $game->turn_phase === TurnPhase::Held;
                                    @endphp

                                    @if (! $cell['exists'])
                                        <div class="aspect-[2/3] rounded-md border-2 border-dashed border-zinc-200 dark:border-zinc-700"></div>
                                    @elseif ($cell['is_face_up'])
                                        <button
                                            @if ($isInteractive)
                                                wire:click="placeCard({{ $cell['position'] }})"
                                                class="aspect-[2/3] cursor-pointer rounded-md border-2 border-transparent font-bold transition hover:border-accent hover:scale-105 {{ \App\View\CardColor::fromValue($cell['value']) }}"
                                            @else
                                                class="aspect-[2/3] cursor-default rounded-md font-bold {{ \App\View\CardColor::fromValue($cell['value']) }}"
                                            @endif
                                            type="button"
                                        >
                                            <span class="flex h-full items-center justify-center text-xs leading-none sm:text-sm">{{ $cell['value'] }}</span>
                                        </button>
                                    @else
                                        <button
                                            @if ($isInteractive)
                                                wire:click="discardAndFlip({{ $cell['position'] }})"
                                                class="aspect-[2/3] cursor-pointer rounded-md border-2 border-transparent bg-slate-700 transition hover:border-accent hover:scale-105 dark:bg-slate-600"
                                            @else
                                                class="aspect-[2/3] cursor-default rounded-md bg-slate-700 dark:bg-slate-600"
                                            @endif
                                            type="button"
                                        >
                                            <span class="flex h-full items-center justify-center text-xs font-bold text-white opacity-30">?</span>
                                        </button>
                                    @endif
                                @endfor
                            @endfor
                        </div>

                        @if (! empty($roundScores[$player['id']] ?? []))
                            <div class="mt-2 flex flex-wrap gap-1 px-1">
                                @foreach ($roundScores[$player['id']] as $round => $score)
                                    <span class="rounded bg-zinc-100 px-1 text-xs text-zinc-500 dark:bg-zinc-800">{{ $score > 0 ? '+' : '' }}{{ $score }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Draw & Discard piles --}}
            <div class="mt-4 flex items-center justify-center gap-6">
                @if ($this->heldCard !== null)
                    <div class="flex flex-col items-center gap-1">
                        <flux:text class="text-xs text-zinc-500">{{ __('Held') }}</flux:text>
                        <div class="flex h-16 w-11 items-center justify-center rounded-lg border-2 border-accent font-bold shadow-md {{ \App\View\CardColor::fromValue($this->heldCard) }}">
                            {{ $this->heldCard }}
                        </div>
                    </div>
                @endif

                @if ($game->turn_phase === TurnPhase::Draw)
                    <div class="flex flex-col items-center gap-1">
                        <flux:text class="text-xs text-zinc-500">{{ __(':n cards', ['n' => $this->drawPileCount]) }}</flux:text>
                        <button
                            wire:click="drawFromPile"
                            class="flex h-16 w-11 cursor-pointer items-center justify-center rounded-lg border-2 border-transparent bg-slate-700 font-bold text-white transition hover:border-accent hover:scale-105 dark:bg-slate-600"
                            type="button"
                        >
                            <flux:icon name="arrow-down-tray" class="size-5" />
                        </button>
                        <flux:text class="text-xs">{{ __('Draw') }}</flux:text>
                    </div>

                    @if ($this->discardTop !== null)
                        <div class="flex flex-col items-center gap-1">
                            <flux:text class="text-xs text-zinc-500">{{ __('Discard') }}</flux:text>
                            <button
                                wire:click="takeFromDiscard"
                                class="flex h-16 w-11 cursor-pointer items-center justify-center rounded-lg border-2 border-transparent font-bold transition hover:border-accent hover:scale-105 {{ \App\View\CardColor::fromValue($this->discardTop) }}"
                                type="button"
                            >
                                {{ $this->discardTop }}
                            </button>
                            <flux:text class="text-xs">{{ __('Take') }}</flux:text>
                        </div>
                    @endif
                @endif

                @if ($game->turn_phase === TurnPhase::Held)
                    <flux:text class="text-sm text-zinc-500">{{ __('Click a card in :name\'s grid to swap or flip', ['name' => $this->currentPlayerName]) }}</flux:text>
                @endif
            </div>

            {{-- Scoreboard toggle --}}
            <div class="mt-4 flex justify-center">
                <flux:modal.trigger name="scoreboard">
                    <flux:button variant="ghost" icon="chart-bar" size="sm">{{ __('Scoreboard') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>

    {{-- ============================= FINISHED ============================= --}}
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

    {{-- Scoreboard modal --}}
    <flux:modal name="scoreboard" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Scoreboard') }}</flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="py-2 text-left font-medium text-zinc-500">{{ __('Player') }}</th>
                            @for ($r = 1; $r <= $game->current_round; $r++)
                                <th class="px-2 py-2 text-center font-medium text-zinc-500">{{ $r }}</th>
                            @endfor
                            <th class="py-2 text-right font-bold">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($players as $player)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 font-medium">{{ $player['name'] }}</td>
                                @for ($r = 1; $r <= $game->current_round; $r++)
                                    @php $score = $roundScores[$player['id']][$r] ?? null; @endphp
                                    <td class="px-2 py-2 text-center">
                                        @if ($score !== null)
                                            <span @class(['text-green-600 dark:text-green-400' => $score < 0])>{{ $score > 0 ? '+' : '' }}{{ $score }}</span>
                                        @else
                                            <span class="text-zinc-300">—</span>
                                        @endif
                                    </td>
                                @endfor
                                <td class="py-2 text-right font-bold">{{ $player['total_score'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <flux:modal.close>
                <flux:button variant="primary" class="w-full">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>
