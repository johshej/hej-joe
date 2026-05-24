<?php

use App\Services\GameEngine;

beforeEach(function () {
    $this->engine = new GameEngine;
});

test('deck has 150 cards', function () {
    expect($this->engine->buildDeck())->toHaveCount(150);
});

test('deck contains correct card distribution', function () {
    $deck = $this->engine->buildDeck();
    $counts = array_count_values($deck);

    expect($counts[-2])->toBe(5);
    expect($counts[-1])->toBe(10);
    expect($counts[0])->toBe(15);

    for ($i = 1; $i <= 12; $i++) {
        expect($counts[$i])->toBe(10, "Value {$i} should appear 10 times");
    }
});

test('deck is shuffled', function () {
    $deck1 = $this->engine->buildDeck();
    $deck2 = $this->engine->buildDeck();

    expect($deck1)->not->toEqual($deck2);
});

test('negative cards score their face value', function () {
    expect($this->engine->scoreCard(-2))->toBe(-2);
    expect($this->engine->scoreCard(-1))->toBe(-1);
    expect($this->engine->scoreCard(0))->toBe(0);
});

test('cards 1-4 score 1 point each', function () {
    expect($this->engine->scoreCard(1))->toBe(1);
    expect($this->engine->scoreCard(2))->toBe(1);
    expect($this->engine->scoreCard(3))->toBe(1);
    expect($this->engine->scoreCard(4))->toBe(1);
});

test('cards 5-9 score 5 points each', function () {
    expect($this->engine->scoreCard(5))->toBe(5);
    expect($this->engine->scoreCard(7))->toBe(5);
    expect($this->engine->scoreCard(9))->toBe(5);
});

test('cards 10-12 score 10 points each', function () {
    expect($this->engine->scoreCard(10))->toBe(10);
    expect($this->engine->scoreCard(11))->toBe(10);
    expect($this->engine->scoreCard(12))->toBe(10);
});

test('round score 70 or more becomes -7', function () {
    $rawScores = [1 => 80, 2 => 20];
    $adjusted = $this->engine->adjustRoundScore(80, 1, false, $rawScores);

    expect($adjusted)->toBe(-7);
});

test('round score exactly 70 becomes -7', function () {
    $rawScores = [1 => 70, 2 => 30];
    $adjusted = $this->engine->adjustRoundScore(70, 1, false, $rawScores);

    expect($adjusted)->toBe(-7);
});

test('round score below 70 is not affected', function () {
    $rawScores = [1 => 69, 2 => 30];
    $adjusted = $this->engine->adjustRoundScore(69, 1, false, $rawScores);

    expect($adjusted)->toBe(69);
});

test('round ender score is doubled when not lowest', function () {
    $rawScores = [1 => 10, 2 => 5, 3 => 8];
    // Player 1 ends round with 10, others have 5 and 8
    $adjusted = $this->engine->adjustRoundScore(10, 1, true, $rawScores);

    expect($adjusted)->toBe(20);
});

test('round ender score is not doubled when they have lowest', function () {
    $rawScores = [1 => 4, 2 => 10, 3 => 8];
    $adjusted = $this->engine->adjustRoundScore(4, 1, true, $rawScores);

    expect($adjusted)->toBe(4);
});

test('70+ rule is applied before doubling', function () {
    // Raw = 72 → capped to -7. Other player has 50, so -7 < 50: doubling does NOT apply.
    $rawScores = [1 => 72, 2 => 50];
    $adjusted = $this->engine->adjustRoundScore(72, 1, true, $rawScores);

    expect($adjusted)->toBe(-7);
});

test('70+ cap score is doubled when still higher than others after cap', function () {
    // Raw = 72 → capped to -7. Other player has -10, so -7 > -10: doubling applies → -14.
    $rawScores = [1 => 72, 2 => -10];
    $adjusted = $this->engine->adjustRoundScore(72, 1, true, $rawScores);

    expect($adjusted)->toBe(-14);
});

test('three matching face values triggers column elimination', function () {
    expect($this->engine->isColumnEliminated([5, 5, 5]))->toBeTrue();
});

test('non-matching values do not trigger elimination', function () {
    expect($this->engine->isColumnEliminated([5, 5, 4]))->toBeFalse();
});

test('incomplete column does not trigger elimination', function () {
    expect($this->engine->isColumnEliminated([5, 5]))->toBeFalse();
});

test('game ends when player hits magic number exactly', function () {
    $players = [
        (new \App\Models\GamePlayer)->forceFill(['id' => 1, 'total_score' => 100]),
        (new \App\Models\GamePlayer)->forceFill(['id' => 2, 'total_score' => 60]),
    ];

    $result = $this->engine->resolveGameEnd($players, 100);

    expect($result)->toHaveCount(1)
        ->and($result[0]->id)->toBe(1);
});

test('game ends when player exceeds threshold', function () {
    $players = [
        (new \App\Models\GamePlayer)->forceFill(['id' => 1, 'total_score' => 105]),
        (new \App\Models\GamePlayer)->forceFill(['id' => 2, 'total_score' => 60]),
    ];

    $result = $this->engine->resolveGameEnd($players, 100);

    expect($result)->toHaveCount(1)
        ->and($result[0]->id)->toBe(2);
});

test('game continues when no player hits threshold', function () {
    $players = [
        (new \App\Models\GamePlayer)->forceFill(['id' => 1, 'total_score' => 40]),
        (new \App\Models\GamePlayer)->forceFill(['id' => 2, 'total_score' => 60]),
    ];

    expect($this->engine->resolveGameEnd($players, 100))->toBeNull();
});

test('tie returns multiple winners', function () {
    $players = [
        (new \App\Models\GamePlayer)->forceFill(['id' => 1, 'total_score' => 110]),
        (new \App\Models\GamePlayer)->forceFill(['id' => 2, 'total_score' => 50]),
        (new \App\Models\GamePlayer)->forceFill(['id' => 3, 'total_score' => 50]),
    ];

    $result = $this->engine->resolveGameEnd($players, 100);

    expect($result)->toHaveCount(2);
});

test('initial face-up positions returns exactly 2 positions', function () {
    $positions = $this->engine->initialFaceUpPositions();

    expect($positions)->toHaveCount(2);
    expect($positions[0])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(11);
    expect($positions[1])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(11);
    expect($positions[0])->not->toEqual($positions[1]);
});
