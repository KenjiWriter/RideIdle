<?php

declare(strict_types=1);

use App\Content\Branding\Branding;
use App\Content\Branding\CssPolicy;
use App\Content\Branding\SharePage;
use App\Content\PackValidator;
use App\Games\CurrentGame;
use App\Models\Game;
use App\Services\ContentImages;

// The look of a game: its colours and fonts, a style sheet of its own, the blocks of its front page and the preview of a link to it. It is a document of the pack
// (rules/branding), judged like the others, and the app asks the server for it by the host: the sheet, the blocks and the page that a robot is given.

/** A picture that the store takes (the game looks at the header of a file, not at what is drawn). */
function brandingPicture(int $side = 64, string $seed = ''): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 2, 0, 0, 0)).$chunk('tEXt', "seed\x00{$seed}").$chunk('IDAT', (string) gzcompress(str_repeat("\x00".str_repeat("\x20\x40\x80", $side), $side))).$chunk('IEND', '');
}

/** The changes to a pack that give it a document of the look: the fields given, on top of a document that says nothing. */
function brandingIs(array $fields): array
{
    return ['rules/branding' => fn (): array => ['schema' => 0, 'version' => '2026.10.0', ...$fields]];
}

/** @return array{errors: list<array{file: string, pointer: string, message: string}>, warnings: list<array{file: string, pointer: string, message: string}>, valid: bool} */
function brandingResult(array $fields): array
{
    $result = PackValidator::fromConfig()->validateSource(contentWith(brandingIs($fields)));

    return ['errors' => $result->errors(), 'warnings' => $result->warnings(), 'valid' => $result->isValid()];
}

function brandingText(string $en, ?string $pl = null): array
{
    return ['en' => $en, 'pl' => $pl ?? $en];
}

/** A second game whose pack has the look given. */
function brandedGame(array $fields, array $attributes = []): Game
{
    $game = Game::query()->create([...['slug' => 'ships', 'name' => 'Star Ships', 'domain' => 'ships.test', 'locale' => 'pl', 'theme_color' => '#0b3d5c', 'status' => Game::STATUS_LIVE], ...$attributes]);
    app(CurrentGame::class)->within($game, fn () => contentPacks()->import(contentWith(brandingIs($fields)), '2026.10.31', activate: true, checkTables: false));

    return $game;
}

describe('the document', function () {
    it('may be left out of a pack, or say nothing: the app is then as it looks', function () {
        $result = PackValidator::fromConfig()->validateSource(repositoryContent());

        expect($result->isValid())->toBeTrue()
            ->and(Branding::fromSource(repositoryContent())->hasTheme())->toBeFalse()
            ->and(Branding::fromSource(repositoryContent())->hasLanding())->toBeFalse()
            ->and(brandingResult([])['valid'])->toBeTrue();
    });

    it('takes colours, fonts, a sheet, a front page and a preview, and tells which languages have no words of their own', function () {
        $result = brandingResult([
            'colors' => ['bg' => '#06121c', 'amber' => '#3ad1c9'],
            'fonts' => ['display' => 'system-serif', 'mono' => 'system-mono'],
            'css' => '.card { border-radius: 2px; }',
            'landing' => ['blocks' => [
                ['type' => 'hero', 'title' => brandingText('Sail', 'Płyń')],
                ['type' => 'features', 'items' => [['title' => brandingText('Hull'), 'text' => brandingText('A hull.')]]],
            ]],
            'share' => ['title' => 'Star Ships', 'description' => brandingText('Sail the stars.', 'Płyń do gwiazd.')],
        ]);

        expect($result['valid'])->toBeTrue()
            ->and(array_column($result['warnings'], 'pointer'))->toContain('/landing/blocks/0/title/fr', '/landing/blocks/1/items/0/text/pt-BR', '/share/description/de');
    });

    it('refuses what is not a colour, a font, a block or a text with English and Polish', function (array $fields) {
        expect(brandingResult($fields)['valid'])->toBeFalse();
    })->with([
        'a colour that is not #rrggbb' => [['colors' => ['bg' => 'red']]],
        'a colour the app does not have' => [['colors' => ['sidebar' => '#000000']]],
        'a font that is not a preset' => [['fonts' => ['sans' => 'Comic Sans']]],
        'a hero with no title' => [['landing' => ['blocks' => [['type' => 'hero']]]]],
        'a features block with no cards' => [['landing' => ['blocks' => [['type' => 'features']]]]],
        'an image with no description' => [['landing' => ['blocks' => [['type' => 'image', 'image' => str_repeat('a', 64).'.png']]]]],
        'a block of a kind that is not one' => [['landing' => ['blocks' => [['type' => 'script', 'title' => ['en' => 'x', 'pl' => 'x']]]]]],
        'a text with no Polish' => [['landing' => ['blocks' => [['type' => 'text', 'body' => ['en' => 'Hello']]]]]],
        'more than twelve blocks' => [['landing' => ['blocks' => array_fill(0, 13, ['type' => 'cta'])]]],
    ]);

    it('says which part of the sheet is wrong, and refuses the pack for it', function () {
        $result = brandingResult(['css' => "@import url(https://example.com/a.css);\n.a { background: url(https://example.com/a.png); }"]);

        expect($result['valid'])->toBeFalse()
            ->and(array_column($result['errors'], 'pointer'))->toBe(['/css', '/css'])
            ->and(implode(' ', array_column($result['errors'], 'message')))->toContain('@import')->toContain('url()');
    });

    it('looks for the pictures it names in the store and tells which are not there', function () {
        $kept = app(ContentImages::class)->store(brandingPicture(64, 'kept'));
        $lost = str_repeat('b', 64).'.png';

        $result = brandingResult(['landing' => ['blocks' => [
            ['type' => 'image', 'image' => $kept, 'alt' => brandingText('Kept')],
            ['type' => 'hero', 'title' => brandingText('Lost'), 'image' => $lost],
        ]]]);

        expect($result['valid'])->toBeTrue()
            ->and(implode(' ', array_column($result['warnings'], 'message')))->toContain($lost)->not->toContain($kept);
    });
});

describe('the style sheet of a game', function () {
    it('may do what a sheet does: rules, media queries with a range, comments, a quotation mark written as an escape, a picture of the game', function (string $css) {
        expect(CssPolicy::problems($css))->toBe([]);
    })->with([
        'rules' => ['.card { border-radius: 2px; color: var(--color-ink); }'],
        'a range in a media query' => ['@media (width < 600px) { .card { padding: 4px; } }'],
        'a quotation mark written as an escape, inside a string' => ['q::before { content: "\\201C"; }'],
        'a comment that mentions what is not allowed' => ["/* @import url(https://example.com/a.css) and url(x) */\n.a { color: red; }"],
        'a string that mentions what is not allowed' => ['.a::after { content: "@import url(x) expression(1)"; }'],
        'a name that ends in url' => ['.a { --my-url: 1; background: var(--my-url); }'],
        'a picture of the game' => ['.a { background: url("/api/v1/media/'.str_repeat('a', 64).'.png") center / cover; }'],
        'a picture of the game, not quoted' => ['.a { background: url(/api/v1/media/'.str_repeat('a', 64).'.webp); }'],
    ]);

    it('refuses what would bring something from outside or hide what it does', function (string $css, string $reason) {
        expect(implode(' ', CssPolicy::problems($css)))->toContain($reason);
    })->with([
        '@import' => ['@import "https://example.com/a.css";', '@import'],
        '@font-face' => ['@font-face { font-family: X; src: url(/api/v1/media/'.str_repeat('a', 64).'.png); }', '@font-face'],
        'an image from another host' => ['.a { background: url(https://example.com/a.png); }', 'url()'],
        'an image from this host that is not a picture of the game' => ['.a { background: url(/api/v1/auth/me); }', 'url()'],
        'a protocol-relative image' => ['.a { background: url("//example.com/a.png"); }', 'url()'],
        'a data address' => ['.a { background: url(data:image/png;base64,AAAA); }', 'url()'],
        'an image-set' => ['.a { background: image-set("https://example.com/a.png" 1x); }', 'image-set()'],
        'an attr()' => ['.a { background: attr(data-x url); }', 'attr()'],
        'an expression' => ['.a { width: expression(alert(1)); }', 'expression()'],
        'a behavior' => ['.a { behavior: url(x.htc); }', 'behavior'],
        'a function name hidden by an escape' => ['.a { background: u\\72l(https://example.com/a.png); }', 'backslash'],
        'a comment that is not closed' => ['.a { color: red; } /* ...', 'comment'],
        'a string that is not closed' => ['.a::after { content: "oops; }', 'string'],
        'a url that is not closed' => ['.a { background: url(', 'url()'],
        'a sheet that is too long' => [str_repeat('.a{color:red}', 2000), 'longer'],
    ]);

    it('names the pictures of the game that it uses', function () {
        $name = str_repeat('c', 64).'.jpg';

        expect(CssPolicy::pictures(".a { background: url('/api/v1/media/{$name}'); } .b { background: url(/api/v1/media/{$name}); }"))->toBe([$name]);
    });

    it('is not served in part: a sheet that the policy refuses is left out, the colours stay', function () {
        $branding = Branding::fromSource(contentWith(brandingIs(['colors' => ['bg' => '#010203'], 'css' => '@import "https://example.com/a.css"; .a { color: red; }'])));

        expect($branding->stylesheet())->toBe(":root{--color-bg:#010203;}\n");
    });
});

describe('what the server says of the look of the host', function () {
    it('has nothing to say for the first game: no sheet to ask for, no front page, and a sheet that says so if it is asked', function () {
        $this->getJson('/api/v1/meta/game')->assertOk()->assertJsonPath('theme_css_url', null)->assertJsonPath('has_landing', false);
        $this->getJson('/api/v1/meta/landing')->assertOk()->assertJsonPath('blocks', []);

        $sheet = $this->get('/api/v1/meta/theme.css')->assertOk();

        expect($sheet->headers->get('Content-Type'))->toContain('text/css')
            ->and($sheet->getContent())->toContain('no style sheet');
    });

    it('serves the sheet of the game of the host: colours and fonts as the variables of the app, then its own rules', function () {
        brandedGame([
            'colors' => ['bg' => '#06121c', 'amber' => '#3ad1c9'],
            'fonts' => ['display' => 'system-serif'],
            'css' => ".card { border-radius: 2px; }\n",
        ]);

        $game = $this->getJson('/api/v1/meta/game', ['X-Game' => 'ships'])->assertOk();
        expect($game->json('theme_css_url'))->toBe('/api/v1/meta/theme.css?v=2026.10.0')->and($game->json('has_landing'))->toBeFalse();

        $sheet = $this->get('/api/v1/meta/theme.css', ['X-Game' => 'ships'])->assertOk();

        expect($sheet->headers->get('Content-Type'))->toContain('text/css')
            ->and($sheet->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($sheet->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60')
            ->and($sheet->getContent())->toBe(":root{--color-bg:#06121c;--color-amber:#3ad1c9;--font-display:Georgia, 'Times New Roman', serif;}\n.card { border-radius: 2px; }\n");

        $this->get('/api/v1/meta/theme.css', ['X-Game' => 'ships', 'If-None-Match' => $sheet->headers->get('ETag')])->assertStatus(304);

        // The first game is still as it was.
        $this->getJson('/api/v1/meta/game')->assertOk()->assertJsonPath('theme_css_url', null);
    });

    it('serves the blocks of the front page with the pictures as addresses', function () {
        $picture = app(ContentImages::class)->store(brandingPicture(64, 'hero'));

        brandedGame(['landing' => ['blocks' => [
            ['type' => 'hero', 'title' => brandingText('Sail', 'Płyń'), 'image' => $picture, 'alt' => brandingText('A ship')],
            ['type' => 'text', 'body' => brandingText("One.\n\nTwo.")],
        ]]]);

        $this->getJson('/api/v1/meta/game', ['X-Game' => 'ships'])->assertJsonPath('has_landing', true);

        $landing = $this->getJson('/api/v1/meta/landing', ['X-Game' => 'ships'])->assertOk();

        expect($landing->json('number'))->toBe('2026.10.0')
            ->and($landing->json('blocks.0.image_url'))->toBe("/api/v1/media/{$picture}")
            ->and($landing->json('blocks.0'))->not->toHaveKey('image')
            ->and($landing->json('blocks.0.title'))->toBe(brandingText('Sail', 'Płyń'))
            ->and($landing->json('blocks.1.body.en'))->toBe("One.\n\nTwo.");
    });

    it('carries the pictures of the look in a bundle', function () {
        $hero = app(ContentImages::class)->store(brandingPicture(64, 'hero'));
        $share = app(ContentImages::class)->store(brandingPicture(64, 'share'));
        $sheet = app(ContentImages::class)->store(brandingPicture(64, 'sheet'));

        $names = app(ContentImages::class)->namesIn(contentWith(brandingIs([
            'css' => ".a { background: url(/api/v1/media/{$sheet}); }",
            'landing' => ['blocks' => [['type' => 'hero', 'title' => brandingText('Sail'), 'image' => $hero]]],
            'share' => ['image' => $share],
        ])));

        expect($names)->toContain($hero, $share, $sheet);
    });
});

describe('the page that a robot is given', function () {
    beforeEach(function () {
        $this->dist = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ride-dist-'.bin2hex(random_bytes(4));
        mkdir($this->dist);
        file_put_contents($this->dist.'/index.html', "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\" />\n<meta name=\"theme-color\" content=\"#0e1013\" />\n<title>RideIdle</title>\n</head>\n<body><div id=\"app\"></div></body>\n</html>\n");
        config(['ride.web_dist_path' => $this->dist]);
    });

    afterEach(function () {
        @unlink($this->dist.'/index.html');
        @rmdir($this->dist);
    });

    it('is the page of the app with the tags of the first game in its head, and the icon as the picture', function () {
        $page = $this->get('/api/v1/meta/share')->assertOk();
        $html = $page->getContent();

        expect($page->headers->get('Content-Type'))->toContain('text/html')
            ->and($html)->toContain('<title>RideIdle</title>')->toContain('<html lang="en">')->toContain('<div id="app">')
            ->and($html)->toContain('<meta property="og:title" content="RideIdle">')->toContain('<meta property="og:description" content="An idle racing game')
            ->and($html)->toContain('<meta property="og:image" content="'.config('ride.web_url').'/icons/icon-512.png">')->toContain('<meta name="twitter:card" content="summary_large_image">')
            ->and(substr_count($html, '<title>'))->toBe(1)->and(substr_count($html, 'name="theme-color"'))->toBe(1);
    });

    it('says what the game says of itself: the title, the description in its language and the picture, on the domain of the game', function () {
        $picture = app(ContentImages::class)->store(brandingPicture(64, 'share'));
        brandedGame(['share' => ['title' => 'Star Ships: sail', 'description' => brandingText('Sail the stars.', 'Płyń do gwiazd.'), 'image' => $picture]]);

        $scheme = parse_url((string) config('ride.web_url'), PHP_URL_SCHEME);
        $html = $this->get('/api/v1/meta/share', ['X-Game' => 'ships'])->assertOk()->getContent();

        expect($html)->toContain('<html lang="pl">')->toContain('<title>Star Ships: sail</title>')
            ->and($html)->toContain('<meta property="og:description" content="Płyń do gwiazd.">')
            ->and($html)->toContain('<meta property="og:site_name" content="Star Ships">')
            ->and($html)->toContain("<meta property=\"og:url\" content=\"{$scheme}://ships.test/\">")
            ->and($html)->toContain("<meta property=\"og:image\" content=\"{$scheme}://ships.test/api/v1/media/{$picture}\">")
            ->and($html)->toContain('<meta name="theme-color" content="#0b3d5c">');
    });

    it('never lets a text become markup, or read a $ or a backslash as a reference', function () {
        $html = SharePage::render(file_get_contents($this->dist.'/index.html'), [
            'lang' => 'en', 'name' => 'A', 'title' => 'Cost $1 \\0 <script>alert(1)</script> "x"', 'description' => '<b>d</b>', 'image' => null, 'url' => 'https://a.test/', 'color' => '#000000',
        ]);

        expect($html)->toContain('<title>Cost $1 \\0 &lt;script&gt;alert(1)&lt;/script&gt; &quot;x&quot;</title>')
            ->and($html)->not->toContain('<script>')->and($html)->toContain('content="&lt;b&gt;d&lt;/b&gt;"')
            ->and($html)->toContain('<meta name="twitter:card" content="summary">');
    });

    it('is only the tags where there is no build of the app to read', function () {
        config(['ride.web_dist_path' => $this->dist.'/nowhere']);

        $html = $this->get('/api/v1/meta/share')->assertOk()->getContent();

        expect($html)->toStartWith('<!doctype html>')->toContain('<title>RideIdle</title>')->not->toContain('id="app"');
    });
});
