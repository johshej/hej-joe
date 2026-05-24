<?php

namespace App\Enums;

enum GameStatus: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Scoring = 'scoring';
    case Reviewing = 'reviewing';
    case Finished = 'finished';
}
