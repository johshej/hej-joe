<?php

namespace App\View;

class CardColor
{
    /**
     * Return Tailwind CSS classes for a card's background and text color based on face value.
     */
    public static function fromValue(?int $value): string
    {
        if ($value === null) {
            return 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300';
        }

        return match (true) {
            $value < 0 => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
            $value === 0 => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
            $value <= 4 => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            $value <= 8 => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            $value <= 9 => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
            default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    }
}
