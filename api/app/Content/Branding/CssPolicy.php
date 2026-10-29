<?php

declare(strict_types=1);

namespace App\Content\Branding;

/**
 * What a style sheet written for a game may do. It comes after the sheet of the app and has the last word on any rule, and that is all it may do: it cannot fetch
 * anything from outside (a sheet, a font, an image from another host), because a request to another host tells that host who is looking at the page, and it cannot
 * hide what it does behind an escape. The only address that a url() may have is a picture of the game (/api/v1/media/<name>), which is then a picture the pack names.
 *
 * The sheet is read the way a browser reads it: comments and strings are skipped (the text of a string may hold anything, a quote or a backslash included, and
 * `content: "\201C"` is how a quotation mark is written), and what is left is looked at for the things that are not allowed. A sheet that is refused is said so with
 * the reason for each thing, and the game then serves no sheet at all, never a part of one.
 */
final class CssPolicy
{
    public const MAX_BYTES = 20000;

    /** The only address a url() may point at, and the name of the picture in it. */
    private const MEDIA = '#^/api/v1/media/([0-9a-f]{64}\.(?:png|webp|jpg))$#';

    /** The rules that load something or change what a sheet is: @import (another sheet), @font-face (a font from anywhere) and @namespace. */
    private const AT_RULES = ['import', 'namespace', 'font-face'];

    /** The functions that take an address, or that read a value as one (`attr()` can make a url out of an attribute of the page). */
    private const FUNCTIONS = ['expression', 'image-set', '-webkit-image-set', 'src', 'image', 'element', 'cross-fade', '-webkit-cross-fade', 'paint', 'attr'];

    /**
     * Everything that is wrong with a sheet, and the pictures of the game that it names.
     *
     * @return array{problems: list<string>, pictures: list<string>}
     */
    public static function inspect(string $css): array
    {
        $problems = [];
        $pictures = [];

        if (strlen($css) > self::MAX_BYTES) {
            $problems[] = 'The style sheet is longer than '.self::MAX_BYTES.' bytes.';
        }

        if (str_contains($css, "\0")) {
            $problems[] = 'The style sheet has a NUL character in it.';
        }

        $bare = self::scan($css, $problems, $pictures);

        foreach (self::AT_RULES as $rule) {
            if (preg_match('/@'.$rule.'(?![a-z0-9_-])/i', $bare) === 1) {
                $problems[] = "@{$rule} is not allowed: a game's sheet cannot bring anything from outside.";
            }
        }

        foreach (self::FUNCTIONS as $function) {
            if (preg_match('/(?<![a-z0-9_-])'.preg_quote($function, '/').'\s*\(/i', $bare) === 1) {
                $problems[] = "{$function}() is not allowed: it can load something from outside.";
            }
        }

        if (preg_match('/(?:javascript\s*:|behavior\s*:|-moz-binding)/i', $bare) === 1) {
            $problems[] = 'A script, a behavior or a binding cannot be a part of a style sheet.';
        }

        return ['problems' => array_values(array_unique($problems)), 'pictures' => array_values(array_unique($pictures))];
    }

    /** @return list<string> what is wrong with the sheet; none for one that is allowed */
    public static function problems(string $css): array
    {
        return self::inspect($css)['problems'];
    }

    /** @return list<string> the pictures of the game that the sheet names, whether or not it is allowed */
    public static function pictures(string $css): array
    {
        return self::inspect($css)['pictures'];
    }

    /**
     * Walks the sheet once. Comments are dropped and strings are made empty in what is given back; a url() is looked at where it stands, because its address may be
     * written as a string or not, and is then replaced by an empty one.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $pictures
     */
    private static function scan(string $css, array &$problems, array &$pictures): string
    {
        $bare = '';
        $length = strlen($css);
        $position = 0;
        $escaped = false;

        while ($position < $length) {
            $char = $css[$position];

            if ($char === '/' && ($css[$position + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $position + 2);

                if ($end === false) {
                    $problems[] = 'A comment is not closed.';

                    break;
                }

                $bare .= ' ';
                $position = $end + 2;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = self::stringEnd($css, $position);

                if ($end === null) {
                    $problems[] = 'A string is not closed.';

                    break;
                }

                $bare .= '""';
                $position = $end + 1;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $position++;

                continue;
            }

            if (($char === 'u' || $char === 'U') && ! self::continuesAName($bare) && preg_match('/\Gurl\s*\(/Ai', $css, $opening, 0, $position) === 1) {
                $end = self::urlEnd($css, $position + strlen($opening[0]), $problems, $pictures);

                if ($end === null) {
                    break;
                }

                $bare .= 'url()';
                $position = $end;

                continue;
            }

            $bare .= $char;
            $position++;
        }

        if ($escaped) {
            $problems[] = 'A backslash outside a string is not allowed: it can hide the name of a function.';
        }

        return $bare;
    }

    /**
     * Where the argument of a url() ends (the place after the closing parenthesis), after judging its address, or null when it is not one that can be read.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $pictures
     */
    private static function urlEnd(string $css, int $from, array &$problems, array &$pictures): ?int
    {
        $length = strlen($css);
        $position = $from;

        while ($position < $length && ctype_space($css[$position])) {
            $position++;
        }

        if ($position >= $length) {
            $problems[] = 'A url() is not closed.';

            return null;
        }

        if ($css[$position] === '"' || $css[$position] === "'") {
            $end = self::stringEnd($css, $position);

            if ($end === null) {
                $problems[] = 'A string is not closed.';

                return null;
            }

            $address = substr($css, $position + 1, $end - $position - 1);
            $position = $end + 1;

            while ($position < $length && ctype_space($css[$position])) {
                $position++;
            }

            if (($css[$position] ?? '') !== ')') {
                $problems[] = 'A url() is not closed.';

                return null;
            }

            $position++;
        } else {
            $close = strpos($css, ')', $position);

            if ($close === false) {
                $problems[] = 'A url() is not closed.';

                return null;
            }

            $address = trim(substr($css, $position, $close - $position));
            $position = $close + 1;
        }

        if (preg_match(self::MEDIA, $address, $found) === 1) {
            $pictures[] = $found[1];
        } else {
            $problems[] = 'A url() may only point at a picture of the game (/api/v1/media/<name>).';
        }

        return $position;
    }

    /** The place of the quote that closes the string that starts at $from, or null when the line ends first (a string does not run over a line). */
    private static function stringEnd(string $css, int $from): ?int
    {
        $quote = $css[$from];
        $length = strlen($css);

        for ($position = $from + 1; $position < $length; $position++) {
            $char = $css[$position];

            if ($char === '\\') {
                $position++;

                continue;
            }

            if ($char === $quote) {
                return $position;
            }

            if ($char === "\n" || $char === "\r" || $char === "\f") {
                return null;
            }
        }

        return null;
    }

    /** Whether what was read last is a part of a name, so that the `url` that follows it is the end of a longer name and not a function. */
    private static function continuesAName(string $bare): bool
    {
        return $bare !== '' && preg_match('/[A-Za-z0-9_-]$/', $bare) === 1;
    }
}
