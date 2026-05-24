<?php

use App\Actions\Games\CreateGame;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Hej-Joe')] class extends Component {
    public Team $teamModel;

    #[Validate('required|in:network,local')]
    public string $mode = 'network';

    #[Validate('required|integer|min:10|max:999')]
    public int $endScore = 100;

    public function mount(Team $currentTeam): void
    {
        $routeParam = request()->route('current_team');
        $this->teamModel = match (true) {
            $currentTeam->exists => $currentTeam,
            $routeParam instanceof Team => $routeParam,
            is_string($routeParam) => Team::where('slug', $routeParam)->firstOrFail(),
            default => $currentTeam,
        };
    }

    public function createGame(CreateGame $createGame): void
    {
        $this->validate();

        $game = $createGame(Auth::user(), $this->teamModel, GameMode::from($this->mode), $this->endScore);

        $this->dispatch('close-modal', name: 'create-game');
        $this->reset('mode', 'endScore');

        Flux::toast(variant: 'success', text: __('Game created!'));

        $this->redirectRoute('games.play', ['current_team' => $this->teamModel->slug, 'game' => $game->invite_code], navigate: true);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Game> */
    #[Computed]
    public function activeGames(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->teamModel->games()
            ->whereIn('status', [GameStatus::Waiting->value, GameStatus::Active->value, GameStatus::Scoring->value])
            ->with('players')
            ->latest()
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Game> */
    #[Computed]
    public function recentGames(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->teamModel->games()
            ->where('status', GameStatus::Finished->value)
            ->with('players')
            ->latest()
            ->limit(10)
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="mb-8 flex items-center justify-between">
        <div>
            <flux:heading size="xl">Hej-Joe</flux:heading>
            <flux:subheading>{{ __('Card game for :team', ['team' => $teamModel->name]) }}</flux:subheading>
        </div>

        <flux:modal.trigger name="create-game">
            <flux:button variant="primary" icon="plus">
                {{ __('New game') }}
            </flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->activeGames->isNotEmpty())
        <div class="mb-8 space-y-4">
            <flux:heading>{{ __('Active games') }}</flux:heading>

            <div class="space-y-3">
                @foreach ($this->activeGames as $game)
                    <a href="{{ route('games.play', ['current_team' => $teamModel->slug, 'game' => $game->invite_code]) }}" wire:navigate
                       class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <div class="flex items-center gap-4">
                            <div class="flex size-10 items-center justify-center rounded-full bg-accent/10 text-accent">
                                <flux:icon name="squares-2x2" class="size-5" />
                            </div>
                            <div>
                                <div class="font-medium">
                                    {{ $game->players->map->displayName()->join(', ') ?: __('No players yet') }}
                                </div>
                                <flux:text class="text-sm">
                                    {{ __('Round :n · :count players · Target: :score pts', ['n' => $game->current_round ?: '—', 'count' => $game->players->count(), 'score' => $game->end_score]) }}
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($game->status === \App\Enums\GameStatus::Waiting)
                                <flux:badge color="yellow">{{ __('Waiting') }}</flux:badge>
                            @else
                                <flux:badge color="green">{{ __('Playing') }}</flux:badge>
                            @endif
                            <flux:icon name="chevron-right" class="text-zinc-400" />
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->recentGames->isNotEmpty())
        <div class="space-y-4">
            <flux:heading>{{ __('Recent games') }}</flux:heading>

            <div class="space-y-3">
                @foreach ($this->recentGames as $game)
                    <a href="{{ route('games.play', ['current_team' => $teamModel->slug, 'game' => $game->invite_code]) }}" wire:navigate
                       class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                        <div>
                            <div class="font-medium">
                                {{ $game->players->map->displayName()->join(', ') }}
                            </div>
                            <flux:text class="text-sm">
                                {{ __(':count rounds · Target: :score pts', ['count' => $game->current_round, 'score' => $game->end_score]) }}
                            </flux:text>
                        </div>
                        <flux:badge color="zinc">{{ __('Finished') }}</flux:badge>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->activeGames->isEmpty() && $this->recentGames->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 py-16 dark:border-zinc-700">
            <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                <flux:icon name="squares-2x2" class="size-8 text-zinc-400" />
            </div>
            <flux:heading class="mb-1">{{ __('No games yet') }}</flux:heading>
            <flux:text class="mb-6 text-center text-zinc-500">{{ __('Create a game and invite your teammates to play.') }}</flux:text>
            <flux:modal.trigger name="create-game">
                <flux:button variant="primary" icon="plus">{{ __('New game') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @endif

    <flux:modal name="create-game" :show="$errors->isNotEmpty()" focusable class="max-w-md">
        <form wire:submit="createGame" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New Hej-Joe game') }}</flux:heading>
                <flux:subheading>{{ __('Set up your game options.') }}</flux:subheading>
            </div>

            <flux:select wire:model="mode" :label="__('Game mode')">
                <flux:select.option value="network">{{ __('Network — each player on their own device') }}</flux:select.option>
                <flux:select.option value="local">{{ __('Local — all players share this screen') }}</flux:select.option>
            </flux:select>

            <flux:input
                wire:model="endScore"
                type="number"
                min="10"
                max="999"
                :label="__('Target score')"
                :description="__('First to hit exactly this score wins. Exceeding it ends the game.')"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create game') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
