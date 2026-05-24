<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;

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
     * Score a single card by tier (not face value for 1-12).
     * -2 → -2, -1 → -1, 0 → 0, 1-4 → 1, 5-9 → 5, 10-12 → 10.
     */
    public function scoreCard(int $value): int
    {
        if ($value <= 0) {
            return $value;
        }

        if ($value <= 4) {
            return 1;
        }

        if ($value <= 9) {
            return 5;
        }

        return 10;
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
     * 1. If raw >= 70 → -7 (before doubling)
     * 2. If the player ended the round and did not have the lowest score → double
     *
     * @param  array<int, int>  $allPlayerRawScores  keyed by game_player_id
     */
    public function adjustRoundScore(int $raw, int $gamePlayerId, bool $isRoundEnder, array $allPlayerRawScores): int
    {
        $score = $raw >= 70 ? -7 : $raw;

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
     * @return array<int, GamePlayer>|null  null = continue; array = winners
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
     * @return array<int, int>  2 positions (0-11)
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
