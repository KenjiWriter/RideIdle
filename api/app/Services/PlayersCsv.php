<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;

/**
 * The accounts of a game as a CSV file for a spreadsheet: one line for each, in the order and with the filters of the list in the panel. The header is in English and the
 * same in every language of the panel, so that a script that reads the file does not depend on it. Nothing in it can sign in as the account: no password, no recovery code.
 * Money is written as a decimal number, dates in UTC as ISO 8601, and the file begins with a byte order mark, which is what makes a spreadsheet read it as UTF-8.
 */
final class PlayersCsv
{
    public const HEADER = ['id', 'name', 'account', 'email', 'level', 'xp', 'cash', 'premium_coins', 'cars', 'paid_purchases', 'locale', 'created_at', 'last_seen_at'];

    /** What a spreadsheet takes for a formula when a cell begins with it. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly PlayerLevels $levels) {}

    /**
     * Writes the file to the output, as the lines are read from the database (the list of a big game is not held in memory).
     *
     * @param  BuilderContract<User>  $players  the query of the list: the accounts with `xp`, `cash_cents`, `premium_coins`, `identity_email`, `cars_count` and `paid_purchases_count`
     * @param  resource  $output
     */
    public function write(BuilderContract $players, $output): int
    {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, self::HEADER, ',', '"', '');

        $lines = 0;

        foreach ($players->cursor() as $player) {
            fputcsv($output, $this->line($player), ',', '"', '');
            $lines++;
        }

        return $lines;
    }

    /** @return list<int|string> */
    public function line(User $player): array
    {
        return [
            $player->id,
            $this->text($player->name),
            $player->isGuest() ? 'guest' : 'secured',
            $this->text($player->email ?: $player->getAttribute('identity_email')),
            $this->levels->of((int) $player->getAttribute('xp')) ?? '',
            (int) $player->getAttribute('xp'),
            number_format(((int) $player->getAttribute('cash_cents')) / 100, 2, '.', ''),
            (int) $player->getAttribute('premium_coins'),
            (int) $player->getAttribute('cars_count'),
            (int) $player->getAttribute('paid_purchases_count'),
            (string) $player->locale,
            $player->created_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? '',
            $player->last_seen_at?->utc()->format('Y-m-d\TH:i:s\Z') ?? '',
        ];
    }

    /** A text that came from a player, made safe to open in a spreadsheet: a cell that begins like a formula is written with an apostrophe in front. */
    public function text(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true) ? "'{$text}" : $text;
    }
}
