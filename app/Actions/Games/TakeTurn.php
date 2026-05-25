<?php

namespace App\Actions\Games;

use App\Enums\GameStatus;
use App\Enums\TurnPhase;
use App\Events\GameStateUpdated;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRoundScore;
use App\Models\PlayerCard;
use App\Services\GameEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TakeTurn
{
    public function __construct(private readonly GameEngine $engine) {}

    /**
     * Draw a new card from the draw pile (phase: draw → held).
     */
    public function drawFromPile(Game $game, GamePlayer $player): void
    {
        $this->assertTurn($game, $player, TurnPhase::Draw);

        DB::transaction(function () use ($game) {
            $game->refresh();
            $drawPile = $game->draw_pile ?? [];

            if (empty($drawPile)) {
                $drawPile = $this->reshuffleDiscard($game);
            }

            $card = array_pop($drawPile);

            $game->update([
                'draw_pile' => array_values($drawPile),
                'held_card_value' => $card,
                'turn_phase' => TurnPhase::Held,
            ]);
        });

        broadcast(new GameStateUpdated($game))->toOthers();
    }

    /**
     * Take the top card from the discard pile (phase: draw → held).
     */
    public function takeFromDiscard(Game $game, GamePlayer $player): void
    {
        $this->assertTurn($game, $player, TurnPhase::Draw);

        DB::transaction(function () use ($game) {
            $game->refresh();
            $discardPile = $game->discard_pile ?? [];

            if (empty($discardPile)) {
                throw ValidationException::withMessages(['game' => __('Discard pile is empty.')]);
            }

            $card = array_pop($discardPile);

            $game->update([
                'discard_pile' => array_values($discardPile),
                'held_card_value' => $card,
                'turn_phase' => TurnPhase::Held,
            ]);
        });

        broadcast(new GameStateUpdated($game))->toOthers();
    }

    /**
     * Place held card at position, discard the replaced card (phase: held → draw, next player).
     */
    public function placeCard(Game $game, GamePlayer $player, int $position): void
    {
        $this->assertTurn($game, $player, TurnPhase::Held);

        DB::transaction(function () use ($game, $player, $position) {
            $game->refresh();
            $existingCard = PlayerCard::where('game_player_id', $player->id)
                ->where('position', $position)
                ->firstOrFail();

            $discardPile = $game->discard_pile ?? [];
            $discardPile[] = $existingCard->value;

            $existingCard->update([
                'value' => $game->held_card_value,
                'is_face_up' => true,
            ]);

            $game->update([
                'discard_pile' => array_values($discardPile),
                'held_card_value' => null,
                'turn_phase' => TurnPhase::Draw,
            ]);

            $this->checkColumnElimination($game, $player, $existingCard->column());
            $this->advanceTurn($game, $player);
        });

        broadcast(new GameStateUpdated($game))->toOthers();
    }

    /**
     * Discard the held card onto the discard pile, then enter the Flip phase so the player
     * must reveal one of their face-down cards (phase: held → flip → draw, next player).
     * If the player has no face-down cards left the turn advances immediately.
     */
    public function discardHeld(Game $game, GamePlayer $player): void
    {
        $this->assertTurn($game, $player, TurnPhase::Held);

        DB::transaction(function () use ($game, $player) {
            $game->refresh();
            $discardPile = $game->discard_pile ?? [];
            $discardPile[] = $game->held_card_value;

            $game->update([
                'discard_pile' => array_values($discardPile),
                'held_card_value' => null,
                'turn_phase' => TurnPhase::Flip,
            ]);

            // Auto-advance when there are no face-down cards left to flip.
            $hasFaceDown = PlayerCard::where('game_player_id', $player->id)
                ->where('is_face_up', false)
                ->exists();

            if (! $hasFaceDown) {
                $this->advanceTurn($game, $player);
            }
        });

        broadcast(new GameStateUpdated($game))->toOthers();
    }

    /**
     * Reveal a face-down card after having discarded the held card (phase: flip → draw, next player).
     */
    public function flipCard(Game $game, GamePlayer $player, int $position): void
    {
        $this->assertTurn($game, $player, TurnPhase::Flip);

        DB::transaction(function () use ($game, $player, $position) {
            $card = PlayerCard::where('game_player_id', $player->id)
                ->where('position', $position)
                ->where('is_face_up', false)
                ->firstOrFail();

            $card->update(['is_face_up' => true]);

            $this->checkColumnElimination($game, $player, $card->column());
            $this->advanceTurn($game, $player);
        });

        broadcast(new GameStateUpdated($game))->toOthers();
    }

    private function assertTurn(Game $game, GamePlayer $player, TurnPhase $phase): void
    {
        if ($game->current_player_id !== $player->id) {
            throw ValidationException::withMessages(['game' => __('It is not your turn.')]);
        }

        if ($game->turn_phase !== $phase) {
            throw ValidationException::withMessages(['game' => __('Invalid action for current turn phase.')]);
        }
    }

    private function checkColumnElimination(Game $game, GamePlayer $player, int $column): void
    {
        $columnCards = $player->cardsInColumn($column);

        if ($columnCards->count() !== 3) {
            return;
        }

        if (! $columnCards->every(fn (PlayerCard $c) => $c->is_face_up)) {
            return;
        }

        $values = $columnCards->pluck('value')->toArray();

        if ($this->engine->isColumnEliminated($values)) {
            $discardPile = $game->discard_pile ?? [];
            foreach ($columnCards as $card) {
                $discardPile[] = $card->value;
            }
            $game->update(['discard_pile' => array_values($discardPile)]);
            PlayerCard::whereIn('id', $columnCards->pluck('id'))->delete();
        }
    }

    /**
     * Advance turn to the next player. Handle round-end detection and scoring.
     */
    private function advanceTurn(Game $game, GamePlayer $player): void
    {
        $game->refresh();

        $remainingCards = PlayerCard::where('game_player_id', $player->id)->count();
        $allRevealed = $remainingCards === 0 || $player->cards()->where('is_face_up', false)->count() === 0;

        if ($allRevealed && ! $player->has_finished_revealing) {
            $player->update(['has_finished_revealing' => true]);

            if ($game->round_ender_id === null) {
                $game->update([
                    'round_ender_id' => $player->id,
                    'status' => GameStatus::Scoring,
                ]);
            }
        }

        $players = $game->players()->orderBy('seat')->get();
        $nextPlayer = $this->engine->nextPlayerAfter($player, $players->all());

        // If we've gone all the way around and everyone has had their final turn
        if ($game->status === GameStatus::Scoring && $nextPlayer->id === $game->round_ender_id) {
            $this->scoreRound($game);

            return;
        }

        $game->update([
            'current_player_id' => $nextPlayer->id,
            'turn_phase' => TurnPhase::Draw,
        ]);
    }

    private function scoreRound(Game $game): void
    {
        $game->refresh();
        $players = $game->players()->with('cards')->get();
        $roundNumber = $game->current_round;
        $rawScores = [];

        foreach ($players as $player) {
            $values = $player->cards->pluck('value')->toArray();
            $rawScores[$player->id] = $this->engine->scoreCards($values);
        }

        $roundEnderRaw = $rawScores[$game->round_ender_id] ?? 0;

        foreach ($players as $player) {
            $raw = $rawScores[$player->id];
            $isEnder = $player->id === $game->round_ender_id;
            $adjusted = $this->engine->adjustRoundScore($raw, $player->id, $isEnder, $rawScores);

            $cappedScore = $raw >= 120 ? -7 : $raw;
            $otherScores = array_filter($rawScores, fn ($id) => $id !== $player->id, ARRAY_FILTER_USE_KEY);
            $isDoubled = $isEnder && ! empty($otherScores) && $cappedScore > min($otherScores);

            GameRoundScore::create([
                'game_id' => $game->id,
                'game_player_id' => $player->id,
                'round_number' => $roundNumber,
                'raw_score' => $raw,
                'adjusted_score' => $adjusted,
                'is_doubled' => $isDoubled,
                'triggered_round_end' => $isEnder,
            ]);

            $player->update(['total_score' => $player->total_score + $adjusted]);
        }

        $freshPlayers = $game->players()->get();
        $winners = $this->engine->resolveGameEnd($freshPlayers->all(), $game->end_score);

        if ($winners !== null) {
            foreach ($winners as $winner) {
                $winner->update(['is_winner' => true]);
            }
        }

        // Always pause for review — players confirm before seeing the next round or final screen.
        $game->update([
            'status' => GameStatus::Reviewing,
            'ready_player_ids' => [],
        ]);
    }

    public function confirmReady(Game $game, GamePlayer $player): void
    {
        $readyIds = $game->ready_player_ids ?? [];

        if (! in_array($player->id, $readyIds)) {
            $readyIds[] = $player->id;
            $game->update(['ready_player_ids' => $readyIds]);
            $game->refresh();
        }

        $allPlayerIds = $game->players()->orderBy('id')->pluck('id')->toArray();
        $confirmedIds = collect($game->ready_player_ids ?? [])->sort()->values()->toArray();
        sort($allPlayerIds);

        if ($confirmedIds === $allPlayerIds) {
            $gameOver = $game->players()->where('is_winner', true)->exists();

            if ($gameOver) {
                $game->update(['status' => GameStatus::Finished]);
            } else {
                $this->startNextRound($game);
            }
        }
    }

    public function startNextRound(Game $game): void
    {
        $players = $game->players()->orderBy('seat')->get();

        // Delete all current round cards
        PlayerCard::whereIn('game_player_id', $players->pluck('id'))->delete();

        // Reset has_finished_revealing
        foreach ($players as $player) {
            $player->update(['has_finished_revealing' => false]);
        }

        $deck = $this->engine->buildDeck();
        $cards = [];

        foreach ($players as $player) {
            $faceUpPositions = $this->engine->initialFaceUpPositions();

            for ($position = 0; $position < 12; $position++) {
                $cards[] = [
                    'game_player_id' => $player->id,
                    'position' => $position,
                    'value' => array_pop($deck),
                    'is_face_up' => in_array($position, $faceUpPositions),
                ];
            }
        }

        PlayerCard::insert($cards);

        $firstPlayer = $this->engine->firstPlayerByStartCards($players, $cards);
        $topDiscard = array_pop($deck);

        $game->update([
            'status' => GameStatus::Active,
            'current_round' => $game->current_round + 1,
            'current_player_id' => $firstPlayer->id,
            'turn_phase' => TurnPhase::Draw,
            'held_card_value' => null,
            'round_ender_id' => null,
            'draw_pile' => array_values($deck),
            'discard_pile' => [$topDiscard],
        ]);
    }

    private function reshuffleDiscard(Game $game): array
    {
        $discardPile = $game->discard_pile ?? [];
        $topCard = array_pop($discardPile);
        shuffle($discardPile);

        $game->update([
            'discard_pile' => $topCard !== null ? [$topCard] : [],
        ]);

        return $discardPile;
    }
}
