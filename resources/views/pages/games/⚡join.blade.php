<?php

use App\Actions\Games\JoinGame;
use App\Enums\GameStatus;
use App\Models\Game;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Join Game')] class extends Component {
    public function mount(string $inviteCode, JoinGame $joinGame): void
    {
        $game = Game::where('invite_code', strtoupper($inviteCode))->firstOrFail();

        if ($game->status !== GameStatus::Waiting) {
            if ($game->players()->where('user_id', Auth::id())->exists()) {
                $this->redirectRoute(
                    'games.play',
                    ['current_team' => $game->team->slug, 'game' => $game->invite_code],
                    navigate: true,
                );

                return;
            }

            Flux::toast(variant: 'danger', text: __('This game has already started.'));

            $this->redirectRoute('home', navigate: true);

            return;
        }

        $joinGame($game, Auth::user());

        $this->redirectRoute(
            'games.play',
            ['current_team' => $game->team->slug, 'game' => $game->invite_code],
            navigate: true,
        );
    }

}; ?>

<div></div>
