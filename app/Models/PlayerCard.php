<?php

namespace App\Models;

use Database\Factories\PlayerCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_player_id', 'position', 'value', 'is_face_up'])]
class PlayerCard extends Model
{
    /** @use HasFactory<PlayerCardFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<GamePlayer, $this>
     */
    public function gamePlayer(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class);
    }

    public function column(): int
    {
        return (int) floor($this->position / 3);
    }

    public function row(): int
    {
        return $this->position % 3;
    }

    protected function casts(): array
    {
        return [
            'is_face_up' => 'boolean',
        ];
    }
}
