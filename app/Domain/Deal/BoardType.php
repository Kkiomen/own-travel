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

    /**
     * The boards an offer can actually be said to carry. "Unknown" is not one
     * of them - it means the article never said, which is reported as no board
     * rather than as a kind of board.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        $known = [];

        foreach (self::cases() as $board) {
            if ($board !== self::Unknown) {
                $known[] = $board->value;
            }
        }

        return $known;
    }
}
