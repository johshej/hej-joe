<?php

namespace App\Models;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\TurnPhase;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'team_id', 'created_by', 'invite_code', 'mode', 'status', 'end_score',
    'current_round', 'current_player_id', 'turn_phase', 'held_card_value',
    'draw_pile', 'discard_pile', 'round_ender_id',
])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Game $game) {
            if (empty($game->invite_code)) {
                $game->invite_code = strtoupper(Str::random(8));
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<GamePlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class)->orderBy('seat');
    }

    /**
     * @return BelongsTo<GamePlayer, $this>
     */
    public function currentPlayer(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'current_player_id');
    }

    /**
     * @return BelongsTo<GamePlayer, $this>
     */
    public function roundEnder(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'round_ender_id');
    }

    /**
     * @return HasMany<GameRoundScore, $this>
     */
    public function roundScores(): HasMany
    {
        return $this->hasMany(GameRoundScore::class);
    }

    public function discardTop(): ?int
    {
        $pile = $this->discard_pile;

        return ! empty($pile) ? last($pile) : null;
    }

    protected function casts(): array
    {
        return [
            'mode' => GameMode::class,
            'status' => GameStatus::class,
            'turn_phase' => TurnPhase::class,
            'draw_pile' => 'array',
            'discard_pile' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'invite_code';
    }
}
