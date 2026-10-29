<?php

declare(strict_types=1);

use App\Content\Branding\Branding;
use App\Content\Definitions\BrandingForm;
use App\Content\PackValidator;
use App\Filament\Forms\BrandingFields;
use App\Filament\Pages\BrandingPage;
use App\Games\CurrentGame;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Models\ContentPublication;
use App\Models\Game;
use App\Services\ContentDrafts;
use App\Services\ContentImages;
use App\Services\ContentPublisher;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

// The look of the game in the panel: its colours and fonts, a style sheet of its own, the blocks of its front page and the preview of a link, kept as the draft of the
// document rules/branding when the content accepts it, and said by the API once a pack that has it is live. The content of the repository is what is in the database,
// and it has no such document: a game starts one with its first draft.

function brandingDefinition(): ?ContentDefinition
{
    return ContentDefinition::query()->where('kind', 'rules')->where('slug', 'branding')->first();
}

/** A picture that the store takes: the game looks at the header of a file, not at what is drawn. */
function brandingPageBytes(int $side = 64, string $seed = ''): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 2, 0, 0, 0)).$chunk('tEXt', "seed\x00{$seed}").$chunk('IDAT', (string) gzcompress(str_repeat("\x00".str_repeat("\x20\x40\x80", $side), $side))).$chunk('IEND', '');
}

/** What the box of a picture holds when a file was chosen: a list of files, which is what the box of the browser sends. @return list<UploadedFile> */
function brandingPageFile(string $bytes, string $name = 'picture.png'): array
{
    return [UploadedFile::fake()->createWithContent($name, $bytes)];
}

/** @return array{en: string, pl: string} */
function brandingPageText(string $en, ?string $pl = null): array
{
    return ['en' => $en, 'pl' => $pl ?? $en];
}

/**
 * The state of the form with some of it changed: what an empty form holds, and the fields given on top (the texts by language are given as en and pl, the other
 * languages stay empty).
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function brandingPageState(array $fields = []): array
{
    $state = [...BrandingForm::toState([]), ...$fields];
    $state['share'] = [...BrandingForm::toState([])['share'], ...($fields['share'] ?? [])];

    return $state;
}

/** A block of the form, with every text language that the kind has an empty box, and the texts given on top. */
function brandingPageBlock(string $type, array $data = []): array
{
    $state = BrandingForm::toState(['landing' => ['blocks' => [['type' => $type]]]])['blocks'][0]['data'];

    return ['type' => $type, 'data' => [...$state, ...$data]];
}

/** @return array<string, mixed> the document of a draft */
function brandingPageDraft(): array
{
    return brandingDefinition()->draftDocument();
}

// The panel speaks Polish to the people who run it (the middleware that sets it is not on the path of a component that is tested alone).
beforeEach(function () {
    app()->setLocale('pl');
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
});

describe('the page', function () {
    it('opens for any admin, empty: a game whose pack has no such document is the app as it looks', function () {
        signedInAdmin(Admin::ROLE_EDITOR);

        $this->get('/admin/branding')->assertOk()->assertSee('Marka i strona')->assertSee('Kolory')->assertSee('Podgląd linku');

        $state = Livewire::test(BrandingPage::class)->assertHasNoFormErrors()->get('data');

        expect(brandingDefinition())->toBeNull()
            ->and(array_filter($state['colors']))->toBe([])
            ->and(array_filter($state['fonts']))->toBe([])
            ->and($state['blocks'])->toBe([])
            ->and($state['css'])->toBeNull();
    });

    it('has a colour for each name that the app reads, with the colour of the app to say what an empty box means', function () {
        expect(array_keys(BrandingFields::APP_COLORS))->toBe(array_keys(Branding::COLORS))
            ->and(array_keys(BrandingFields::APP_FONTS))->toBe(array_keys(Branding::FONT_ROLES));

        // The colours of the app are the ones of its style sheet, when the web app is next to the API.
        $css = dirname(base_path()).'/web/src/style.css';

        if (is_file($css)) {
            foreach (BrandingFields::APP_COLORS as $name => $color) {
                expect((string) file_get_contents($css))->toContain('--color-'.str_replace('_', '-', $name).': '.$color.';');
            }
        }
    });

    it('has the words of the page in every language of the panel', function () {
        foreach (['en', 'pl', 'fr', 'de', 'pt_BR'] as $locale) {
            $words = require base_path("lang/{$locale}/admin.php");

            expect(array_keys($words['branding']['color_names']))->toBe(array_keys(Branding::COLORS), $locale)
                ->and(array_keys($words['branding']['font_names']))->toBe(array_keys(Branding::FONTS), $locale)
                ->and(array_keys($words['branding']['font_roles']))->toBe(array_keys(Branding::FONT_ROLES), $locale)
                ->and(array_keys($words['branding']['blocks']))->toBe(array_keys(BrandingForm::BLOCKS), $locale);
        }
    });
});

describe('the form and the document', function () {
    it('make the document that changes nothing out of a form with nothing in it', function () {
        expect(BrandingForm::apply([], BrandingForm::toState([]), '2026.10.0'))->toBe(['schema' => 0, 'version' => '2026.10.0']);
    });

    it('give a document back as it was: colours, fonts, the sheet, every kind of block, the preview of a link', function () {
        $picture = str_repeat('a', 64).'.png';
        $document = [
            'schema' => 0,
            'version' => '2026.10.1',
            'colors' => ['bg' => '#06121c', 'amber' => '#3ad1c9', 'r5' => '#ffffff'],
            'fonts' => ['display' => 'system-serif', 'mono' => 'system-mono'],
            'css' => ".card { border-radius: 2px; }\n.hz { opacity: .5; }",
            'landing' => ['blocks' => [
                ['type' => 'hero', 'eyebrow' => brandingPageText('Sail'), 'title' => brandingPageText('Sail the stars', 'Płyń do gwiazd'), 'subtitle' => brandingPageText('A game.'), 'image' => $picture, 'alt' => brandingPageText('A ship')],
                ['type' => 'features', 'items' => [
                    ['kicker' => brandingPageText('One'), 'title' => brandingPageText('Hull'), 'text' => brandingPageText('A hull.')],
                    ['title' => brandingPageText('Sail'), 'text' => brandingPageText('A sail.', 'Żagiel.')],
                ]],
                ['type' => 'text', 'title' => brandingPageText('Story'), 'body' => brandingPageText("One.\n\nTwo.")],
                ['type' => 'image', 'image' => $picture, 'alt' => brandingPageText('A ship'), 'caption' => brandingPageText('At sea')],
                ['type' => 'cta', 'title' => brandingPageText('Go'), 'text' => brandingPageText('Now.')],
            ]],
            'share' => ['title' => 'Star Ships', 'description' => brandingPageText('Sail.', 'Płyń.'), 'image' => $picture],
        ];

        expect(BrandingForm::apply($document, BrandingForm::toState($document), '2026.10.0'))->toBe($document)
            ->and(PackValidator::fromConfig()->validateSource(contentWith(['rules/branding' => fn (): array => $document]))->isValid())->toBeTrue();
    });

    it('leave out what has nothing in it: a language with an empty box, a card with nothing on it, a sheet of white space', function () {
        $state = brandingPageState([
            'css' => "  \n ",
            'blocks' => [brandingPageBlock('features', ['items' => [
                ['kicker' => ['en' => null], 'title' => ['en' => null], 'text' => ['en' => null]],
                ['kicker' => ['en' => ''], 'title' => ['en' => ' Hull ', 'pl' => 'Kadłub', 'fr' => ''], 'text' => ['en' => 'A hull.', 'pl' => 'Kadłub.']],
            ]])],
        ]);

        expect(BrandingForm::apply([], $state, '2026.10.0')['landing']['blocks'])->toBe([
            ['type' => 'features', 'items' => [['title' => ['en' => 'Hull', 'pl' => 'Kadłub'], 'text' => ['en' => 'A hull.', 'pl' => 'Kadłub.']]]],
        ])->and(BrandingForm::apply([], $state, '2026.10.0'))->not->toHaveKey('css');
    });
});

describe('keeping a draft', function () {
    it('keeps an empty form as a document that says nothing, and the draft makes the definition', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)->call('save')->assertHasNoFormErrors()->assertNotified(__('admin.definitions.saved'));

        expect(brandingPageDraft())->toBe(['schema' => 0, 'version' => repositoryDocument('rules/starter')['version']])
            ->and(brandingDefinition()->status)->toBe(ContentDefinition::STATUS_DRAFT);
    });

    it('keeps what is chosen: colours, fonts, a sheet, blocks of every kind and the preview of a link', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState([
                'colors' => [...BrandingForm::toState([])['colors'], 'bg' => '#06121c', 'amber' => '#3ad1c9'],
                'fonts' => [...BrandingForm::toState([])['fonts'], 'display' => 'system-serif'],
                'css' => ".card { border-radius: 2px; }\n",
                'blocks' => [
                    brandingPageBlock('hero', ['title' => brandingPageText('Sail', 'Płyń'), 'subtitle' => brandingPageText('A game.')]),
                    brandingPageBlock('features', ['items' => [['kicker' => [], 'title' => brandingPageText('Hull'), 'text' => brandingPageText('A hull.')]]]),
                    brandingPageBlock('text', ['body' => brandingPageText("One.\n\nTwo.")]),
                    brandingPageBlock('cta'),
                ],
                'share' => ['title' => 'Star Ships', 'description' => brandingPageText('Sail.', 'Płyń.')],
            ]))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.definitions.saved'));

        expect(brandingPageDraft())->toBe([
            'schema' => 0,
            'version' => repositoryDocument('rules/starter')['version'],
            'colors' => ['bg' => '#06121c', 'amber' => '#3ad1c9'],
            'fonts' => ['display' => 'system-serif'],
            'css' => '.card { border-radius: 2px; }',
            'landing' => ['blocks' => [
                ['type' => 'hero', 'title' => brandingPageText('Sail', 'Płyń'), 'subtitle' => brandingPageText('A game.')],
                ['type' => 'features', 'items' => [['title' => brandingPageText('Hull'), 'text' => brandingPageText('A hull.')]]],
                ['type' => 'text', 'body' => brandingPageText("One.\n\nTwo.")],
                ['type' => 'cta'],
            ]],
            'share' => ['title' => 'Star Ships', 'description' => brandingPageText('Sail.', 'Płyń.')],
        ]);
    });

    it('shows the draft again when the page is opened, and takes a colour off when its box is emptied', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)->fillForm(brandingPageState(['colors' => [...BrandingForm::toState([])['colors'], 'bg' => '#06121c', 'ink' => '#ffffff']]))->call('save');

        $page = Livewire::test(BrandingPage::class);
        expect($page->get('data.colors.bg'))->toBe('#06121c')->and($page->get('data.colors.ink'))->toBe('#ffffff');

        $page->set('data.colors.ink', null)->call('save')->assertHasNoFormErrors();

        expect(brandingPageDraft()['colors'])->toBe(['bg' => '#06121c']);
    });

    it('does not take what the form refuses, and says what is wrong: a colour that is not #rrggbb, a hero with no title', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['blocks' => [brandingPageBlock('hero', ['eyebrow' => brandingPageText('Only this')])]]))
            ->call('save')
            ->assertHasFormErrors();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['colors' => [...BrandingForm::toState([])['colors'], 'bg' => 'red']]))
            ->call('save')
            ->assertHasFormErrors();

        expect(brandingDefinition())->toBeNull();
    });

    it('does not take what only the schema refuses: a document that the form made but the content does not accept', function () {
        signedInAdmin();

        // More blocks than a front page has: the builder stops at twelve, and the schema says the same.
        $result = app(ContentDrafts::class)->validate('rules', ['schema' => 0, 'version' => '2026.10.0', 'landing' => ['blocks' => array_fill(0, 13, ['type' => 'cta'])]], 'branding');

        expect($result->isValid())->toBeFalse()->and(brandingDefinition())->toBeNull();
    });

    it('asks for English and Polish once a language of a text has something in it', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['blocks' => [brandingPageBlock('hero', ['title' => brandingPageText('Sail'), 'eyebrow' => ['en' => 'Only English', 'pl' => null]])]]))
            ->call('save')
            ->assertHasFormErrors();

        expect(brandingDefinition())->toBeNull();
    });

    it('refuses a style sheet that would bring something from outside, says why, and keeps nothing', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['css' => "@import url(https://example.com/a.css);\n.a { background: url(https://example.com/a.png); }"]))
            ->call('save')
            ->assertNotified(__('admin.definitions.invalid'));

        // The notification says what the content says of the sheet: the pointer and each reason.
        $result = app(ContentDrafts::class)->validate('rules', ['schema' => 0, 'version' => '2026.10.0', 'css' => '@import url(https://example.com/a.css);
.a { background: url(https://example.com/a.png); }'], 'branding');

        expect(brandingDefinition())->toBeNull()
            ->and(array_column($result->errors(), 'pointer'))->toBe(['/css', '/css'])
            ->and(implode(' ', array_column($result->errors(), 'message')))->toContain('@import')->toContain('url()');
    });
});

describe('the pictures', function () {
    it('are kept when they are chosen, and the name of the picture is in the block and in the preview of a link', function () {
        signedInAdmin();
        $hero = brandingPageBytes(64, 'hero');
        $share = brandingPageBytes(64, 'share');

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState([
                'blocks' => [brandingPageBlock('hero', ['title' => brandingPageText('Sail'), 'image_upload' => brandingPageFile($hero)])],
                'share' => ['image_upload' => brandingPageFile($share)],
            ]))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin.definitions.saved'));

        $draft = brandingPageDraft();

        expect($draft['landing']['blocks'][0]['image'])->toBe(hash('sha256', $hero).'.png')
            ->and($draft['share']['image'])->toBe(hash('sha256', $share).'.png')
            ->and(app(ContentImages::class)->find($draft['landing']['blocks'][0]['image'])['bytes'])->toBe($hero)
            ->and($draft)->not->toHaveKey('image_upload')
            ->and(json_encode($draft))->not->toContain('image_upload');
    });

    it('stay when nothing new is chosen, are shown, and go when they are taken off', function () {
        signedInAdmin();
        $bytes = brandingPageBytes(64, 'hero');
        $name = hash('sha256', $bytes).'.png';

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['blocks' => [brandingPageBlock('hero', ['title' => brandingPageText('Sail'), 'image_upload' => brandingPageFile($bytes)])]]))
            ->call('save');

        // Opened again, the block has the name, the preview shows it, and a change of the title keeps it.
        $page = Livewire::test(BrandingPage::class)->assertSeeHtml("/api/v1/media/{$name}");
        $blocks = $page->get('data.blocks');
        $key = array_key_first($blocks);

        expect($blocks[$key]['data']['image'])->toBe($name);

        $page->set("data.blocks.{$key}.data.title.en", 'Sail on')->call('save')->assertHasNoFormErrors();

        expect(brandingPageDraft()['landing']['blocks'][0])->toMatchArray(['image' => $name, 'title' => brandingPageText('Sail on', 'Sail')]);

        $page = Livewire::test(BrandingPage::class);
        $key = array_key_first($page->get('data.blocks'));
        $page->set("data.blocks.{$key}.data.image_remove", true)->call('save')->assertHasNoFormErrors();

        expect(brandingPageDraft()['landing']['blocks'][0])->not->toHaveKey('image');
    });

    it('say why a file is not taken, and keep no draft', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['share' => ['image_upload' => brandingPageFile('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"/>')]]))
            ->call('save')
            ->assertNotified(__('admin.definitions.image_refused.kind'));

        expect(brandingDefinition())->toBeNull();
    });

    it('are needed by a block of a picture', function () {
        signedInAdmin();

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState(['blocks' => [brandingPageBlock('image', ['alt' => brandingPageText('A ship')])]]))
            ->call('save')
            ->assertHasFormErrors();

        expect(brandingDefinition())->toBeNull();
    });
});

describe('the games', function () {
    /** A second game, live, with the content of the repository. */
    function brandingPageSecondGame(): Game
    {
        $game = Game::query()->create(['slug' => 'statki', 'name' => 'Statki', 'domain' => 'statki.example.test', 'locale' => 'pl', 'theme_color' => '#0b3a5b', 'status' => Game::STATUS_LIVE]);
        app(CurrentGame::class)->within($game, fn () => contentPacks()->import(repositoryContent(), checkTables: false, activate: true));

        return $game;
    }

    it('have a draft each: the look that is worked on is the one of the game that the panel is in', function () {
        $second = brandingPageSecondGame();
        signedInAdmin();

        Livewire::test(BrandingPage::class)->fillForm(brandingPageState(['colors' => [...BrandingForm::toState([])['colors'], 'bg' => '#111111']]))->call('save')->assertHasNoFormErrors();

        app(CurrentGame::class)->set($second);

        expect(brandingDefinition())->toBeNull()
            ->and(Livewire::test(BrandingPage::class)->get('data.colors.bg'))->toBeNull();

        Livewire::test(BrandingPage::class)->fillForm(brandingPageState(['colors' => [...BrandingForm::toState([])['colors'], 'bg' => '#222222']]))->call('save')->assertHasNoFormErrors();

        expect(brandingPageDraft()['colors'])->toBe(['bg' => '#222222']);

        app(CurrentGame::class)->set(Game::query()->where('slug', 'raceidle')->firstOrFail());

        expect(brandingPageDraft()['colors'])->toBe(['bg' => '#111111']);
    });

    it('are given the look by a publication: the API says it once the pack is live, the pack carries the picture, and another game is as it was', function () {
        $second = brandingPageSecondGame();
        $publisher = signedInAdmin(Admin::ROLE_PUBLISHER);
        $picture = brandingPageBytes(64, 'hero');
        $name = hash('sha256', $picture).'.png';

        Livewire::test(BrandingPage::class)
            ->fillForm(brandingPageState([
                'colors' => [...BrandingForm::toState([])['colors'], 'bg' => '#06121c', 'amber' => '#3ad1c9'],
                'fonts' => [...BrandingForm::toState([])['fonts'], 'display' => 'system-serif'],
                'css' => '.card { border-radius: 2px; }',
                'blocks' => [brandingPageBlock('hero', ['title' => brandingPageText('Sail', 'Płyń'), 'image_upload' => brandingPageFile($picture)])],
                'share' => ['title' => 'Star Ships', 'description' => brandingPageText('Sail the stars.', 'Płyń do gwiazd.')],
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $publication = app(ContentPublisher::class)->queue($publisher);
        $publication->refresh();

        expect($publication->status)->toBe(ContentPublication::STATUS_READY)
            ->and(app(ContentPublisher::class)->activate($publication->pack, $publisher))->toBeTrue()
            ->and(brandingDefinition()->hasDraft())->toBeFalse()
            ->and(brandingDefinition()->publishedDocument()['colors'])->toBe(['bg' => '#06121c', 'amber' => '#3ad1c9'])
            // The pack made out of the drafts has the document, and a bundle of it carries the picture that the front page names.
            ->and(array_keys(json_decode(app(ContentPublisher::class)->export($publication->pack), true)['images']))->toBe([$name]);

        // The game reads the pack that is live, from here on.
        readFromDatabase();

        $this->get('/api/v1/meta/theme.css')->assertOk()->assertSee(':root{--color-bg:#06121c;--color-amber:#3ad1c9;--font-display:Georgia', escape: false)->assertSee('.card { border-radius: 2px; }');
        $this->getJson('/api/v1/meta/landing')->assertOk()->assertJsonPath('blocks.0.title.pl', 'Płyń')->assertJsonPath('blocks.0.image_url', "/api/v1/media/{$name}");
        $this->getJson('/api/v1/meta/game')->assertOk()->assertJsonPath('has_landing', true)->assertJsonPath('theme_css_url', '/api/v1/meta/theme.css?v='.$publication->number);
        $this->get('/api/v1/meta/share')->assertOk()->assertSee('<title>Star Ships</title>', escape: false)->assertSee('content="Sail the stars."', escape: false);

        // Another game has its own pack and its own look: nothing of this reaches it.
        $this->getJson('/api/v1/meta/game', ['X-Game' => $second->slug])->assertOk()->assertJsonPath('theme_css_url', null)->assertJsonPath('has_landing', false);
    });
});
