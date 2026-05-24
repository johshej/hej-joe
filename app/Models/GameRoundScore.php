<?php

namespace App\Models;

use Database\Factories\GameRoundScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_id', 'game_player_id', 'round_number', 'raw_score', 'adjusted_score', 'is_doubled', 'triggered_round_end'])]
class GameRoundScore extends Model
{
    /** @use HasFactory<GameRoundScoreFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<GamePlayer, $this>
     */
    public function gamePlayer(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class);
    }

    protected function casts(): array
    {
        return [
            'is_doubled' => 'boolean',
            'triggered_round_end' => 'boolean',
        ];
    }
}
