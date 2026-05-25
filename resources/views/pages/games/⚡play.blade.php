<?php

use App\Actions\Games\StartGame;
use App\Actions\Games\TakeTurn;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\TurnPhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Services\GameEngine;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Game $game;

    public Team $teamModel;

    /** @var array<int, array<string, mixed>> */
    public array $players = [];

    /** @var array<int, array<int, int>> round scores keyed by [player_id][round_number] */
    public array $roundScores = [];

    /**
     * @var array<int, array<int, array<string, mixed>>>
     * Keyed by game_player_id → round_number → [raw, adjusted, doubled, ended_round]
     */
    public array $roundScoreDetails = [];

    public function confirmReady(int $playerId, TakeTurn $takeTurn): void
    {
        $player = $this->game->players()->findOrFail($playerId);
        $takeTurn->confirmReady($this->game->fresh(), $player);
        $this->game->refresh();
        $this->loadState();
    }

    public ?int $myPlayerId = null;

    public string $inviteUrl = '';

    public function mount(Game $game, Team $currentTeam): void
    {
        $routeParam = request()->route('current_team');
        $resolvedTeam = match (true) {
            $currentTeam->exists => $currentTeam,
            $routeParam instanceof Team => $routeParam,
            is_string($routeParam) => Team::where('slug', $routeParam)->firstOrFail(),
            default => abort(404),
        };

        abort_if($game->team_id !== $resolvedTeam->id, 404);

        $this->game = $game;
        $this->teamModel = $resolvedTeam;
        $this->loadState();
    }

    public function startGame(StartGame $startGame): void
    {
        if ($this->game->created_by !== Auth::id()) {
            abort(403);
        }

        if ($this->game->players()->count() < 2) {
            Flux::toast(variant: 'danger', text: __('At least 2 players are needed to start.'));

            return;
        }

        $startGame($this->game);

        $this->game->refresh();
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
        if ($this->game->mode === GameMode::Local) {
            return $this->game->currentPlayer;
        }

        return $this->game->players()->where('user_id', Auth::id())->firstOrFail();
    }

    #[Computed]
    public function isMyTurn(): bool
    {
        if ($this->game->mode === GameMode::Local) {
            return true;
        }

        return $this->game->current_player_id !== null
            && $this->myPlayerId !== null
            && $this->game->current_player_id === $this->myPlayerId;
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
    public function isCreator(): bool
    {
        return $this->game->created_by === Auth::id();
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

    #[Computed]
    public function isLocalMode(): bool
    {
        return $this->game->mode === GameMode::Local;
    }

    public function render()
    {
        return $this->view()->title('Hej-Joe');
    }

    public function loadState(): void
    {
        $this->inviteUrl = route('games.join', ['inviteCode' => $this->game->invite_code]);

        $myPlayer = $this->game->players()->where('user_id', Auth::id())->first();
        $this->myPlayerId = $myPlayer?->id;

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
                'is_me' => $player->id === $this->myPlayerId,
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
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>  grid[row][col]
     */
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
                    'scored' => $card && $card->is_face_up ? $engine->scoreCard($card->value) : null,
                    'is_face_up' => $card ? $card->is_face_up : false,
                    'exists' => $card !== null,
                ];
            }
        }

        return $grid;
    }
}; ?>

<div
    class="min-h-screen w-full bg-zinc-50 dark:bg-zinc-950"
    x-data
    @if ($game->mode === GameMode::Network)
        x-init="
            window.Echo?.join('game.{{ $game->id }}')
                .listen('.player.joined', () => $wire.$refresh())
                .listen('.game.started', () => $wire.call('loadState'))
                .listen('.state.updated', () => $wire.call('loadState'));
        "
        x-on:navigate.away.window="window.Echo?.leave('game.{{ $game->id }}')"
    @endif
>
    {{-- ============================= LOBBY ============================= --}}
    @if ($game->status === GameStatus::Waiting)
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 text-center">
                <flux:heading size="xl">{{ __('Game Lobby') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Waiting for players to join…') }}</flux:text>
            </div>

            {{-- Player list --}}
            <div class="mb-6 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __(':count / 8 players', ['count' => count($players)]) }}</flux:heading>
                </div>
                @foreach ($players as $player)
                    <div class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3 last:border-0 dark:border-zinc-800">
                        <flux:avatar :name="$player['name']" />
                        <span class="font-medium">{{ $player['name'] }}</span>
                        @if ($player['seat'] === 0)
                            <flux:badge color="yellow" class="ml-auto">{{ __('Host') }}</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Invite URL (network mode) --}}
            @if ($game->mode === GameMode::Network)
                <div class="mb-6 space-y-2">
                    <flux:heading size="sm">{{ __('Invite players') }}</flux:heading>
                    <div
                        class="flex items-center gap-2"
                        x-data="{ copied: false, copy() { navigator.clipboard.writeText('{{ $inviteUrl }}'); this.copied = true; setTimeout(() => this.copied = false, 2000); } }"
                    >
                        <flux:input readonly value="{{ $inviteUrl }}" class="font-mono text-sm" />
                        <flux:button icon="document-duplicate" @click="copy()" x-show="!copied">{{ __('Copy') }}</flux:button>
                        <flux:button icon="check" variant="filled" x-show="copied" x-cloak>{{ __('Copied!') }}</flux:button>
                    </div>
                    <flux:text class="text-sm text-zinc-500">{{ __('Or share the code: ') }}<span class="font-mono font-bold">{{ $game->invite_code }}</span></flux:text>
                </div>
            @endif

            {{-- Game settings --}}
            <div class="mb-6 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                <dl class="flex gap-8 text-sm">
                    <div>
                        <dt class="text-zinc-500">{{ __('Target score') }}</dt>
                        <dd class="font-semibold">{{ $game->end_score }} pts</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Mode') }}</dt>
                        <dd class="font-semibold">{{ $game->mode === GameMode::Local ? __('Local') : __('Network') }}</dd>
                    </div>
                </dl>
            </div>

            @if ($this->isCreator)
                <flux:button
                    wire:click="startGame"
                    variant="primary"
                    class="w-full"
                    :disabled="count($players) < 2"
                >
                    {{ count($players) < 2 ? __('Waiting for more players…') : __('Start game') }}
                </flux:button>
            @else
                <div class="py-4 text-center">
                    <flux:icon.loading class="mx-auto mb-2 size-6 text-zinc-400" />
                    <flux:text class="text-zinc-500">{{ __('Waiting for the host to start the game…') }}</flux:text>
                </div>
            @endif
        </div>

    {{-- ============================= GAME BOARD ============================= --}}
    @elseif (in_array($game->status->value, [GameStatus::Active->value, GameStatus::Scoring->value]))
        @php
            $panelCols = match(true) { count($players) >= 4 => 4, count($players) === 3 => 3, default => 2 };
            $cwFormulas = [2 => 'clamp(24px, calc(12.5vw - 15px), 80px)', 3 => 'clamp(20px, calc(8.33vw - 15px), 72px)', 4 => 'clamp(18px, calc(6.25vw - 15px), 64px)'];
            $cwStyle = '--cw: ' . ($cwFormulas[$panelCols] ?? $cwFormulas[2]) . ';';
        @endphp
        <div class="p-2 sm:p-4" style="{{ $cwStyle }}">
            {{-- Top bar: round, target score, current turn --}}
            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <flux:badge color="zinc">{{ __('Round :n', ['n' => $game->current_round]) }}</flux:badge>
                    <flux:badge color="zinc">{{ __('Target: :score pts', ['score' => $game->end_score]) }}</flux:badge>
                    @if ($game->status === GameStatus::Scoring)
                        <flux:badge color="yellow">{{ __('Final turns!') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="text-sm font-medium">
                    @if ($this->isMyTurn && $game->mode !== GameMode::Local)
                        <span class="text-green-600 dark:text-green-400">{{ __('Your turn!') }}</span>
                    @else
                        {{ __(':name\'s turn', ['name' => $this->currentPlayerName]) }}
                    @endif
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
                    @php
                        $isCurrentPlayer = $player['is_current'];
                        $canSwap = $isCurrentPlayer
                            && $game->turn_phase === TurnPhase::Held
                            && ($this->isMyTurn || $game->mode === GameMode::Local);
                        $canFlip = $isCurrentPlayer
                            && $game->turn_phase === TurnPhase::Flip
                            && ($this->isMyTurn || $game->mode === GameMode::Local);
                    @endphp

                    <div @class([
                        'rounded-xl border bg-white p-2 dark:bg-zinc-900 transition',
                        'border-accent ring-2 ring-accent/50' => $isCurrentPlayer,
                        'border-zinc-200 dark:border-zinc-700' => ! $isCurrentPlayer,
                    ])>
                        {{-- Player header --}}
                        <div class="mb-2 flex items-center justify-between px-1">
                            <span class="text-sm font-semibold">
                                {{ $player['name'] }}
                                @if ($player['is_me'] && $game->mode !== GameMode::Local)
                                    <span class="text-xs font-normal text-zinc-500">({{ __('you') }})</span>
                                @endif
                            </span>
                            <span class="text-sm font-bold">{{ $player['total_score'] }} pts</span>
                        </div>

                        {{-- 3×4 Card grid --}}
                        <div class="mx-auto grid gap-1" style="grid-template-columns: repeat(4, var(--cw));">
                            @for ($row = 0; $row < 3; $row++)
                                @for ($col = 0; $col < 4; $col++)
                                    @php $cell = $player['cards'][$row][$col]; @endphp
                                    @include('games._card-cell', ['cell' => $cell, 'canSwap' => $canSwap, 'canFlip' => $canFlip])
                                @endfor
                            @endfor
                        </div>

                        {{-- Round scores history (compact) --}}
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

            {{-- Draw & Discard piles (shown when it's your turn) --}}
            @if ($this->isMyTurn || $game->mode === GameMode::Local)
                <div class="mt-4 flex items-center justify-center gap-6">
                    {{-- Held card --}}
                    @if ($this->heldCard !== null)
                        <div class="flex flex-col items-center gap-1">
                            <flux:text class="text-xs text-zinc-500">{{ __('Held') }}</flux:text>
                            @include('games._held-card', ['value' => $this->heldCard])
                        </div>
                    @endif

                    @if ($game->turn_phase === TurnPhase::Draw)
                        {{-- Draw pile --}}
                        <div class="flex flex-col items-center gap-1">
                            <flux:text class="text-xs text-zinc-500">{{ __(':n cards', ['n' => $this->drawPileCount]) }}</flux:text>
                            <button
                                wire:click="drawFromPile"
                                class="flex cursor-pointer items-center justify-center overflow-hidden rounded-lg border-2 border-transparent bg-slate-700 font-bold text-white transition hover:scale-105 hover:border-accent dark:bg-slate-600"
                                style="width: var(--cw); aspect-ratio: 2/3;"
                                type="button"
                            >
                                <flux:icon name="arrow-down-tray" class="size-5" />
                            </button>
                            <flux:text class="text-xs">{{ __('Draw') }}</flux:text>
                        </div>

                        {{-- Discard pile --}}
                        @if ($this->discardTop !== null)
                            <div class="flex flex-col items-center gap-1">
                                <flux:text class="text-xs text-zinc-500">{{ __('Discard') }}</flux:text>
                                <button
                                    wire:click="takeFromDiscard"
                                    class="flex cursor-pointer flex-col overflow-hidden rounded-lg border-2 border-transparent font-bold transition hover:scale-105 hover:border-accent {{ \App\View\CardColor::fromValue($this->discardTop) }}"
                                    style="width: var(--cw); aspect-ratio: 2/3;"
                                    type="button"
                                >
                                    <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="leading-none" style="font-size: calc(var(--cw) * 0.42);">{{ $this->discardTop }}</span></div>
                                    <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                    <div class="flex flex-1 items-center justify-center"><span class="leading-none" style="font-size: calc(var(--cw) * 0.42);">{{ $this->discardTop }}</span></div>
                                </button>
                                <flux:text class="text-xs">{{ __('Take') }}</flux:text>
                            </div>
                        @endif
                    @endif

                    @if ($game->turn_phase === TurnPhase::Held)
                        <div class="flex flex-col items-center gap-1">
                            <flux:button wire:click="discardHeld" variant="ghost" size="sm" icon="arrow-up-tray">
                                {{ __('Discard') }}
                            </flux:button>
                            <flux:text class="text-xs text-zinc-500">{{ __('then flip a hidden card') }}</flux:text>
                        </div>
                    @endif
                    @if ($game->turn_phase === TurnPhase::Flip)
                        <flux:text class="text-sm text-yellow-600 dark:text-yellow-400">{{ __('Click one of your hidden cards to reveal it') }}</flux:text>
                    @endif
                </div>
            @else
                {{-- Other player's turn — show piles passively --}}
                <div class="mt-4 flex items-center justify-center gap-4 opacity-50">
                    <div class="overflow-hidden rounded-lg bg-slate-700 dark:bg-slate-600" style="width: var(--cw); aspect-ratio: 2/3;">
                        <x-card-back class="h-full w-full rounded-lg" />
                    </div>
                    @if ($this->discardTop !== null)
                        <div class="flex flex-col overflow-hidden rounded-lg font-bold {{ \App\View\CardColor::fromValue($this->discardTop) }}" style="width: var(--cw); aspect-ratio: 2/3;">
                            <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);"><span class="leading-none" style="font-size: calc(var(--cw) * 0.42);">{{ $this->discardTop }}</span></div>
                            <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                            <div class="flex flex-1 items-center justify-center"><span class="leading-none" style="font-size: calc(var(--cw) * 0.42);">{{ $this->discardTop }}</span></div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Score board toggle --}}
            <div class="mt-4 flex justify-center">
                <flux:modal.trigger name="scoreboard">
                    <flux:button variant="ghost" icon="chart-bar" size="sm">{{ __('Scoreboard') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>

    {{-- ============================= REVIEWING ============================= --}}
    @elseif ($game->status === GameStatus::Reviewing)
        @php
            $lastRound  = $game->current_round;
            $gameOver   = collect($players)->contains('is_winner', true);
            $winners    = collect($players)->filter(fn ($p) => $p['is_winner'])->values();
            $myPlayer   = collect($players)->firstWhere('is_me', true);
            $myReady    = $myPlayer && in_array($myPlayer['id'], $game->ready_player_ids ?? []);
            $engine     = app(\App\Services\GameEngine::class);
        @endphp
        <div class="mx-auto max-w-3xl space-y-6 overflow-y-auto px-4 py-8">

            {{-- Winner banner --}}
            @if ($gameOver)
                <div class="rounded-xl bg-yellow-100 px-4 py-3 text-center dark:bg-yellow-900/40">
                    @if ($winners->count() === 1)
                        <flux:heading size="lg">🎉 {{ $winners->first()['name'] }} {{ __('wins!') }}</flux:heading>
                    @else
                        <flux:heading size="lg">🎉 {{ __("It's a tie!") }}</flux:heading>
                        <flux:text>{{ $winners->pluck('name')->join(' & ') }}</flux:text>
                    @endif
                </div>
            @endif

            {{-- Score table --}}
            <div class="rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $lastRound + 1])
            </div>

            {{-- Per-player card grids + round breakdown --}}
            @foreach (collect($players)->sortBy('total_score') as $player)
                @php
                    $detail = $roundScoreDetails[$player['id']][$lastRound] ?? null;
                @endphp
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        @if ($player['is_winner'])
                            <flux:icon name="trophy" class="size-4 text-yellow-500" />
                        @endif
                        <span class="font-semibold">{{ $player['name'] }}</span>
                        @if ($player['is_me'])
                            <span class="text-xs text-zinc-500">({{ __('you') }})</span>
                        @endif
                        <span class="ml-auto font-bold">{{ $player['total_score'] }} pts total</span>
                    </div>

                    <div class="mb-3 grid gap-1" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
                        @for ($row = 0; $row < 3; $row++)
                            @for ($col = 0; $col < 4; $col++)
                                @php
                                    $cell = $player['cards'][$row][$col];
                                    $scored = $cell['exists'] ? $engine->scoreCard($cell['value']) : null;
                                @endphp
                                @if ($cell['exists'])
                                    <div class="flex flex-col overflow-hidden rounded font-bold {{ \App\View\CardColor::fromValue($cell['value']) }}" style="aspect-ratio: 2/3;">
                                        <div class="flex flex-1 items-center justify-center text-xl font-bold leading-none" style="transform: rotate(180deg);">{{ $cell['value'] }}</div>
                                        <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
                                        <div class="flex flex-1 items-center justify-center text-xl font-bold leading-none">{{ $cell['value'] }}</div>
                                        <div class="shrink-0 bg-black/10 py-0.5 text-center text-xs font-normal leading-none">
                                            {{ $scored > 0 ? '+' : '' }}{{ $scored }}
                                        </div>
                                    </div>
                                @else
                                    <div class="rounded border border-dashed border-zinc-200 dark:border-zinc-700" style="aspect-ratio: 2/3;"></div>
                                @endif
                            @endfor
                        @endfor
                    </div>

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

            {{-- Ready buttons --}}
            @if ($game->mode === GameMode::Local)
                {{-- Local: one button per player on the shared screen --}}
                <div class="flex gap-3">
                    @foreach ($players as $player)
                        @php $isReady = in_array($player['id'], $game->ready_player_ids ?? []); @endphp
                        @if ($isReady)
                            <flux:button disabled class="flex-1">✓ {{ $player['name'] }}</flux:button>
                        @else
                            <flux:button wire:click="confirmReady({{ $player['id'] }})" variant="primary" class="flex-1">
                                {{ $gameOver ? __('See results') : __('Ready') }} — {{ $player['name'] }}
                            </flux:button>
                        @endif
                    @endforeach
                </div>
            @elseif ($myPlayer)
                {{-- Network: only the current user confirms for themselves --}}
                @if ($myReady)
                    <flux:button disabled class="w-full">✓ {{ $gameOver ? __('See results') : __('Ready') }}</flux:button>
                @else
                    <flux:button wire:click="confirmReady({{ $myPlayer['id'] }})" variant="primary" class="w-full">
                        {{ $gameOver ? __('See results') : __('Ready') }}
                    </flux:button>
                @endif
            @endif
        </div>

    {{-- ============================= FINISHED ============================= --}}
    @else
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 text-center">
                @php $winners = array_filter($players, fn($p) => $p['is_winner']); @endphp
                @if (count($winners) === 1)
                    <flux:heading size="xl">🎉 {{ reset($winners)['name'] }} {{ __('wins!') }}</flux:heading>
                @elseif (count($winners) > 1)
                    <flux:heading size="xl">🎉 {{ __('It\'s a tie!') }}</flux:heading>
                    <flux:text>{{ implode(' & ', array_column($winners, 'name')) }}</flux:text>
                @endif
            </div>

            {{-- Final scores --}}
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

            <div class="flex gap-3">
                <flux:button :href="route('games.index', ['current_team' => $teamModel->slug])" wire:navigate class="flex-1" variant="primary">
                    {{ __('New game') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Scoreboard Modal --}}
    <flux:modal name="scoreboard" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Scoreboard') }}</flux:heading>

            <div class="overflow-x-auto">
                @include('games._scoretable', ['players' => $players, 'roundScores' => $roundScores, 'currentRound' => $game->current_round])
            </div>

            <flux:modal.close>
                <flux:button variant="primary" class="w-full">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>
