<?php

namespace App\Models;

use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['game_id', 'user_id', 'guest_name', 'seat', 'total_score', 'is_winner', 'has_finished_revealing'])]
class GamePlayer extends Model
{
    /** @use HasFactory<GamePlayerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PlayerCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(PlayerCard::class)->orderBy('position');
    }

    /**
     * @return HasMany<GameRoundScore, $this>
     */
    public function roundScores(): HasMany
    {
        return $this->hasMany(GameRoundScore::class);
    }

    public function displayName(): string
    {
        return $this->user?->name ?? $this->guest_name ?? 'Player';
    }

    /**
     * Cards in a specific column (0-3). Column n = positions n*3, n*3+1, n*3+2.
     *
     * @return Collection<int, PlayerCard>
     */
    public function cardsInColumn(int $column): Collection
    {
        $positions = [$column * 3, $column * 3 + 1, $column * 3 + 2];

        return $this->cards()->whereIn('position', $positions)->get();
    }

    protected function casts(): array
    {
        return [
            'is_winner' => 'boolean',
            'has_finished_revealing' => 'boolean',
        ];
    }
}
