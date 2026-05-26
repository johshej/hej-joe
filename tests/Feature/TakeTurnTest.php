<?php

use App\Actions\Games\TakeTurn;
use App\Enums\TurnPhase;
use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Validation\ValidationException;

/**
 * Put the game into Held phase with a known held card and known discard pile.
 */
function putInHeldPhase(Game $game, int $heldValue, array $discardPile = [99]): void
{
    $game->update([
        'turn_phase' => TurnPhase::Held,
        'held_card_value' => $heldValue,
        'discard_pile' => $discardPile,
    ]);
}

// ─── drawFromPile ──────────────────────────────────────────────────────────────

test('drawFromPile moves top card from draw pile to held card', function () {
    ['game' => $game, 'current' => $current] = startGame();

    $drawPileBefore = $game->draw_pile;
    $expectedCard = last($drawPileBefore);

    (new TakeTurn(app(GameEngine::class)))->drawFromPile($game, $current);

    $game->refresh();
    expect($game->held_card_value)->toBe($expectedCard);
    expect($game->turn_phase)->toBe(TurnPhase::Held);
    expect($game->draw_pile)->toHaveCount(count($drawPileBefore) - 1);
});

test('drawFromPile fails when it is not the player\'s turn', function () {
    ['game' => $game, 'other' => $other] = startGame();

    expect(fn () => (new TakeTurn(app(GameEngine::class)))->drawFromPile($game, $other))
        ->toThrow(ValidationException::class);
});

// ─── takeFromDiscard ───────────────────────────────────────────────────────────

test('takeFromDiscard moves top discard card to held card', function () {
    ['game' => $game, 'current' => $current] = startGame();

    $game->update(['discard_pile' => [3, 7]]);
    $game->refresh();

    (new TakeTurn(app(GameEngine::class)))->takeFromDiscard($game, $current);

    $game->refresh();
    expect($game->held_card_value)->toBe(7);
    expect($game->turn_phase)->toBe(TurnPhase::Held);
    expect($game->discard_pile)->toBe([3]);
});

// ─── placeCard ─────────────────────────────────────────────────────────────────

test('placeCard swaps held card into position and discards the replaced card', function () {
    ['game' => $game, 'current' => $current] = startGame();

    $current->cards()->where('position', 0)->update(['value' => 9, 'is_face_up' => false]);
    putInHeldPhase($game, 4, [1]);

    (new TakeTurn(app(GameEngine::class)))->placeCard($game, $current, 0);

    $game->refresh();
    expect($game->turn_phase)->toBe(TurnPhase::Draw);
    expect($current->cards()->where('position', 0)->value('value'))->toBe(4);
    expect($current->cards()->where('position', 0)->value('is_face_up'))->toBeTrue();
    expect(last($game->discard_pile))->toBe(9);
});

test('placeCard fails when it is not the player\'s turn', function () {
    ['game' => $game, 'other' => $other] = startGame();
    putInHeldPhase($game, 5);

    expect(fn () => (new TakeTurn(app(GameEngine::class)))->placeCard($game, $other, 0))
        ->toThrow(ValidationException::class);
});

// ─── discardHeld ───────────────────────────────────────────────────────────────

test('discardHeld puts held card onto discard pile and enters Flip phase', function () {
    ['game' => $game, 'current' => $current] = startGame();

    putInHeldPhase($game, 6, [2]);

    (new TakeTurn(app(GameEngine::class)))->discardHeld($game, $current);

    $game->refresh();
    expect(last($game->discard_pile))->toBe(6);
    expect($game->held_card_value)->toBeNull();
    expect($game->turn_phase)->toBe(TurnPhase::Flip);
});

// ─── undoDiscard ───────────────────────────────────────────────────────────────

test('undoDiscard restores held card from discard pile and returns to Held phase', function () {
    ['game' => $game, 'current' => $current] = startGame();

    putInHeldPhase($game, 6, [2]);
    (new TakeTurn(app(GameEngine::class)))->discardHeld($game, $current);
    $game->refresh();

    (new TakeTurn(app(GameEngine::class)))->undoDiscard($game, $current);

    $game->refresh();
    expect($game->held_card_value)->toBe(6);
    expect($game->discard_pile)->toBe([2]);
    expect($game->turn_phase)->toBe(TurnPhase::Held);
});

test('undoDiscard fails when not in Flip phase', function () {
    ['game' => $game, 'current' => $current] = startGame();
    putInHeldPhase($game, 6);

    expect(fn () => (new TakeTurn(app(GameEngine::class)))->undoDiscard($game, $current))
        ->toThrow(ValidationException::class);
});

test('undoDiscard fails when it is not the player\'s turn', function () {
    ['game' => $game, 'other' => $other] = startGame();
    $game->update(['turn_phase' => TurnPhase::Flip]);

    expect(fn () => (new TakeTurn(app(GameEngine::class)))->undoDiscard($game, $other))
        ->toThrow(ValidationException::class);
});

// ─── flipCard ──────────────────────────────────────────────────────────────────

test('flipCard reveals a face-down card and advances turn', function () {
    ['game' => $game, 'current' => $current] = startGame();

    $current->cards()->where('position', 0)->update(['value' => 3, 'is_face_up' => false]);
    $game->update(['turn_phase' => TurnPhase::Flip]);

    (new TakeTurn(app(GameEngine::class)))->flipCard($game, $current, 0);

    $game->refresh();
    expect($current->cards()->where('position', 0)->value('is_face_up'))->toBeTrue();
    expect($game->turn_phase)->toBe(TurnPhase::Draw);
});

// ─── column elimination ────────────────────────────────────────────────────────

test('placing a card that completes a matching column discards the replaced card then the 3 alike cards', function () {
    ['game' => $game, 'current' => $current] = startGame();

    // Column 0 = positions 0, 1, 2. Two face-up 5s, one face-down 3.
    $current->cards()->whereIn('position', [0, 1])->update(['value' => 5, 'is_face_up' => true]);
    $current->cards()->where('position', 2)->update(['value' => 3, 'is_face_up' => false]);

    // Hold a 5; start with a known discard pile.
    putInHeldPhase($game, 5, [99]);

    (new TakeTurn(app(GameEngine::class)))->placeCard($game, $current, 2);

    $game->refresh();

    // Column cards are removed.
    expect($current->cards()->whereIn('position', [0, 1, 2])->count())->toBe(0);

    // Order: 99 (was there), 3 (replaced card), then the 3 alike 5s on top.
    expect($game->discard_pile)->toBe([99, 3, 5, 5, 5]);
    expect(last($game->discard_pile))->toBe(5);
});

test('flipping a card that completes a matching column discards the 3 alike cards', function () {
    ['game' => $game, 'current' => $current] = startGame();

    // Column 0 = positions 0, 1, 2 all value 7; one still face-down.
    $current->cards()->whereIn('position', [0, 1])->update(['value' => 7, 'is_face_up' => true]);
    $current->cards()->where('position', 2)->update(['value' => 7, 'is_face_up' => false]);

    $game->update(['turn_phase' => TurnPhase::Flip, 'discard_pile' => [99]]);

    (new TakeTurn(app(GameEngine::class)))->flipCard($game, $current, 2);

    $game->refresh();

    expect($current->cards()->whereIn('position', [0, 1, 2])->count())->toBe(0);
    expect($game->discard_pile)->toBe([99, 7, 7, 7]);
    expect(last($game->discard_pile))->toBe(7);
});

test('column is not eliminated when the placed card does not match', function () {
    ['game' => $game, 'current' => $current] = startGame();

    $current->cards()->whereIn('position', [0, 1])->update(['value' => 5, 'is_face_up' => true]);
    $current->cards()->where('position', 2)->update(['value' => 4, 'is_face_up' => false]);

    // Place a 6 — column becomes [5, 5, 6], no elimination.
    putInHeldPhase($game, 6, [99]);

    (new TakeTurn(app(GameEngine::class)))->placeCard($game, $current, 2);

    $game->refresh();

    expect($current->cards()->whereIn('position', [0, 1, 2])->count())->toBe(3);
    expect($game->discard_pile)->toBe([99, 4]);
});

test('column is not eliminated when one card is still face-down', function () {
    ['game' => $game, 'current' => $current] = startGame();

    $current->cards()->where('position', 0)->update(['value' => 5, 'is_face_up' => true]);
    $current->cards()->where('position', 1)->update(['value' => 5, 'is_face_up' => false]);
    $current->cards()->where('position', 2)->update(['value' => 3, 'is_face_up' => false]);

    putInHeldPhase($game, 5, [99]);

    // Place on position 2 — column is [5(up), 5(down), 5(up)], position 1 still hidden.
    (new TakeTurn(app(GameEngine::class)))->placeCard($game, $current, 2);

    $game->refresh();

    expect($current->cards()->whereIn('position', [0, 1, 2])->count())->toBe(3);
});
