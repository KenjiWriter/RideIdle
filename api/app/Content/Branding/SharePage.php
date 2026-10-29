<?php

declare(strict_types=1);

namespace App\Content\Branding;

/**
 * The page that a robot is given in place of the app. A chat or a social network makes the preview of a link from the tags in the head of the page, and it does not
 * run the app to find them, so the page it is given is the page of the app with the tags of the game in its head. Nothing in the tags is HTML: every text is escaped.
 */
final class SharePage
{
    /**
     * @param  string|null  $shell  the page of the app (web/dist/index.html), or null where the API stands alone and there is none
     * @param  array{lang: string, name: string, title: string, description: string, image: string|null, url: string, color: string}  $tags
     */
    public static function render(?string $shell, array $tags): string
    {
        $head = self::tags($tags);

        if ($shell === null || stripos($shell, '</head>') === false) {
            return '<!doctype html><html lang="'.self::escape($tags['lang']).'"><head><meta charset="utf-8">'.$head.'</head><body></body></html>';
        }

        // What the shell says of the game as the car game has it is taken out, so that a tag is never there twice.
        $shell = preg_replace('~<title>.*?</title>~si', '', $shell) ?? $shell;
        $shell = preg_replace('~<meta\s+(?:name|property)="(?:description|theme-color|og:[^"]*|twitter:[^"]*)"[^>]*>~i', '', $shell) ?? $shell;
        $shell = preg_replace_callback('~<html\s+lang="[^"]*"~i', static fn (): string => '<html lang="'.self::escape($tags['lang']).'"', $shell, 1) ?? $shell;
        $end = (int) stripos($shell, '</head>');

        // The tags are put in by position and not by a pattern: a $ or a \ in a title would be read as a reference in the replacement of one.
        return substr($shell, 0, $end).$head.substr($shell, $end);
    }

    /** @param  array{lang: string, name: string, title: string, description: string, image: string|null, url: string, color: string}  $tags */
    private static function tags(array $tags): string
    {
        $lines = [
            '<title>'.self::escape($tags['title']).'</title>',
            self::meta('name', 'theme-color', $tags['color']),
            self::meta('property', 'og:type', 'website'),
            self::meta('property', 'og:site_name', $tags['name']),
            self::meta('property', 'og:title', $tags['title']),
            self::meta('property', 'og:url', $tags['url']),
            self::meta('name', 'twitter:card', $tags['image'] === null ? 'summary' : 'summary_large_image'),
            self::meta('name', 'twitter:title', $tags['title']),
        ];

        if ($tags['description'] !== '') {
            $lines[] = self::meta('name', 'description', $tags['description']);
            $lines[] = self::meta('property', 'og:description', $tags['description']);
            $lines[] = self::meta('name', 'twitter:description', $tags['description']);
        }

        if ($tags['image'] !== null) {
            $lines[] = self::meta('property', 'og:image', $tags['image']);
            $lines[] = self::meta('name', 'twitter:image', $tags['image']);
        }

        return implode("\n", $lines)."\n";
    }

    private static function meta(string $attribute, string $name, string $content): string
    {
        return '<meta '.$attribute.'="'.self::escape($name).'" content="'.self::escape($content).'">';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
