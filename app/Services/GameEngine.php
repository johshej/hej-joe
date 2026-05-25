<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Collection;

class GameEngine
{
    /**
     * Build a shuffled 150-card Skyjo deck.
     * Distribution: -2×5, -1×10, 0×15, 1-12 each×10.
     *
     * @return array<int, int>
     */
    public function buildDeck(): array
    {
        $deck = array_merge(
            array_fill(0, 5, -2),
            array_fill(0, 10, -1),
            array_fill(0, 15, 0),
        );

        for ($value = 1; $value <= 12; $value++) {
            $deck = array_merge($deck, array_fill(0, 10, $value));
        }

        shuffle($deck);

        return array_values($deck);
    }

    /**
     * Pick the first player for a round: whoever has the highest sum of their
     * face-up start cards. Tie-break: lowest seat (first in $players).
     *
     * @param  Collection<int, GamePlayer>  $players  ordered by seat
     * @param  array<int, array<string, mixed>>  $cards  the just-inserted card rows
     */
    public function firstPlayerByStartCards(Collection $players, array $cards): GamePlayer
    {
        $sums = [];

        foreach ($cards as $card) {
            if ($card['is_face_up']) {
                $id = $card['game_player_id'];
                $sums[$id] = ($sums[$id] ?? 0) + $card['value'];
            }
        }

        $best = null;
        $bestSum = PHP_INT_MIN;

        foreach ($players as $player) {
            $sum = $sums[$player->id] ?? 0;
            if ($sum > $bestSum) {
                $bestSum = $sum;
                $best = $player;
            }
        }

        return $best ?? $players->first();
    }

    /**
     * Score a single card at face value.
     */
    public function scoreCard(int $value): int
    {
        return $value;
    }

    /**
     * Sum the scored values of a set of card values.
     *
     * @param  array<int, int>  $cardValues
     */
    public function scoreCards(array $cardValues): int
    {
        return array_sum(array_map([$this, 'scoreCard'], $cardValues));
    }

    /**
     * Apply round score rules in order:
     * 1. If raw >= 100 → -7 (before doubling)
     * 2. If the player ended the round and did not have the lowest score → double
     *
     * @param  array<int, int>  $allPlayerRawScores  keyed by game_player_id
     */
    public function adjustRoundScore(int $raw, int $gamePlayerId, bool $isRoundEnder, array $allPlayerRawScores): int
    {
        $score = $raw >= 100 ? -7 : $raw;

        if ($isRoundEnder) {
            $otherScores = array_filter(
                $allPlayerRawScores,
                fn ($id) => $id !== $gamePlayerId,
                ARRAY_FILTER_USE_KEY,
            );

            if (! empty($otherScores) && $score > min($otherScores)) {
                $score *= 2;
            }
        }

        return $score;
    }

    /**
     * Check if all cards in a column have the same face value.
     *
     * @param  array<int, int>  $columnValues  3 values for the column (keyed by position or plain array)
     */
    public function isColumnEliminated(array $columnValues): bool
    {
        if (count($columnValues) !== 3) {
            return false;
        }

        $values = array_values($columnValues);

        return $values[0] === $values[1] && $values[1] === $values[2];
    }

    /**
     * Determine game end result after a round's scores are applied.
     * Returns the winning player(s) if the game should end, or null to continue.
     *
     * @param  array<int, GamePlayer>  $players
     * @return array<int, GamePlayer>|null null = continue; array = winners
     */
    public function resolveGameEnd(array $players, int $endScore): ?array
    {
        $magicWinners = array_filter($players, fn (GamePlayer $p) => $p->total_score === $endScore);

        if (! empty($magicWinners)) {
            return array_values($magicWinners);
        }

        $exceeded = array_filter($players, fn (GamePlayer $p) => $p->total_score > $endScore);

        if (! empty($exceeded)) {
            $minScore = min(array_map(fn (GamePlayer $p) => $p->total_score, $players));

            return array_values(array_filter($players, fn (GamePlayer $p) => $p->total_score === $minScore));
        }

        return null;
    }

    /**
     * Choose two random positions per player to start face-up.
     *
     * @return array<int, int> 2 positions (0-11)
     */
    public function initialFaceUpPositions(): array
    {
        $positions = range(0, 11);
        shuffle($positions);

        return array_slice($positions, 0, 2);
    }

    /**
     * Advance to the next player seat, wrapping around.
     *
     * @param  array<int, GamePlayer>  $players  ordered by seat
     */
    public function nextPlayerAfter(GamePlayer $current, array $players): GamePlayer
    {
        $seats = array_column($players, null, 'seat');
        ksort($seats);
        $seatList = array_values($seats);
        $count = count($seatList);

        foreach ($seatList as $index => $player) {
            if ($player->id === $current->id) {
                return $seatList[($index + 1) % $count];
            }
        }

        return $seatList[0];
    }
}
