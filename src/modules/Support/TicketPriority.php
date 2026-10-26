<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\InformationException;

/**
 * ClientFence: ticket priority as WHMCS: Low, Medium or High. Clients pick it when they open a
 * ticket ("Urgency", Medium by default), staff change it on the ticket, and escalation rules match
 * and set it.
 *
 * Stored in FOSSBilling's support_ticket.priority number, whose default (100) is Medium, so
 * existing tickets need no change: 50 is Low and 150 is High. Any other number (set through the
 * API before) reads as the nearest level.
 */
final class TicketPriority
{
    public const LOW = 50;
    public const MEDIUM = 100;
    public const HIGH = 150;

    public const LEVELS = ['low' => self::LOW, 'medium' => self::MEDIUM, 'high' => self::HIGH];

    /**
     * The stored number for a level ('low', 'medium', 'high', any case) or for one of the three
     * numbers; null or '' is Medium.
     */
    public static function fromInput(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::MEDIUM;
        }
        if (is_string($value) && isset(self::LEVELS[strtolower(trim($value))])) {
            return self::LEVELS[strtolower(trim($value))];
        }
        if ((is_int($value) || (is_string($value) && ctype_digit(trim($value)))) && in_array((int) $value, self::LEVELS, true)) {
            return (int) $value;
        }

        throw new InformationException('Choose a priority: Low, Medium or High.');
    }

    /** The level of a stored number: below 100 Low, above 100 High, else Medium. */
    public static function level(mixed $priority): string
    {
        $priority = is_numeric($priority) ? (int) $priority : self::MEDIUM;

        return match (true) {
            $priority < self::MEDIUM => 'low',
            $priority > self::MEDIUM => 'high',
            default => 'medium',
        };
    }

    /**
     * The levels' names, highest first (form choices).
     *
     * @return array<string, string>
     */
    public static function titles(): array
    {
        return ['high' => __trans('High'), 'medium' => __trans('Medium'), 'low' => __trans('Low')];
    }

    /**
     * The numbers a level filter matches: the level's own number and the old numbers it reads as.
     *
     * @return array{0: string, 1: int} [operator, number] for `priority <op> number`
     */
    public static function filter(string $level): array
    {
        return match (strtolower(trim($level))) {
            'low' => ['<', self::MEDIUM],
            'medium' => ['=', self::MEDIUM],
            'high' => ['>', self::MEDIUM],
            default => throw new InformationException('Choose a priority: Low, Medium or High.'),
        };
    }
}
