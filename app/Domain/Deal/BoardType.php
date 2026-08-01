<?php

declare(strict_types=1);

namespace App\Domain\Deal;

enum BoardType: string
{
    case AllInclusive = 'all_inclusive';
    case FullBoard = 'full_board';
    case HalfBoard = 'half_board';
    case Breakfast = 'breakfast';
    case RoomOnly = 'room_only';
    case Unknown = 'unknown';
}
