<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Content\Branding\Branding;
use App\Content\Definitions\BrandingForm;
use App\Content\Definitions\DefinitionForm;
use App\Services\ContentImages;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;

/**
 * The look of a game as a form: the colours and fonts of its app, a style sheet of its own, the blocks of its front page and the preview of a link to it. Every part
 * is optional, and an empty box is the app's own. The texts of the front page are a box in every language of the game: English and Polish are asked for once a
 * language has a text in the box, and the others fall back to English.
 */
final class BrandingFields
{
    /** The colours that the app has (the `@theme` of web/src/style.css): what an empty box means, shown under it. */
    public const APP_COLORS = [
        'bg' => '#0e1013',
        'surface' => '#151920',
        'surface_2' => '#1c2129',
        'surface_3' => '#262c36',
        'line' => '#2c323d',
        'line_2' => '#3b4252',
        'ink' => '#eceef2',
        'muted' => '#b3bac7',
        'faint' => '#939bab',
        'amber' => '#ffb224',
        'amber_ink' => '#1a1203',
        'amber_bg' => '#1b1509',
        'cyan' => '#42c9e8',
        'cyan_bg' => '#0a1e25',
        'ok' => '#6ad487',
        'danger' => '#ff7a6b',
        'r1' => '#aeb5c2',
        'r2' => '#6ad487',
        'r3' => '#58a8ff',
        'r4' => '#ba86ff',
        'r5' => '#ffb224',
    ];

    /** The fonts that the app has for each place that takes one. */
    public const APP_FONTS = ['display' => 'barlow-condensed', 'sans' => 'barlow', 'mono' => 'jetbrains-mono'];

    /** @return list<mixed> */
    public static function schema(): array
    {
        return [
            Text::make(__('admin.branding.hint')),
            self::colors(),
            self::fonts(),
            self::sheet(),
            self::landing(),
            self::share(),
        ];
    }

    private static function colors(): Section
    {
        $boxes = [];

        foreach (array_keys(Branding::COLORS) as $name) {
            $boxes[] = ColorPicker::make("colors.{$name}")
                ->label(__("admin.branding.color_names.{$name}"))
                ->helperText(__('admin.branding.color_default', ['color' => self::APP_COLORS[$name]]))
                ->regex('/^#[0-9a-fA-F]{6}$/');
        }

        return Section::make(__('admin.branding.colors'))
            ->description(__('admin.branding.colors_hint'))
            ->collapsible()
            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->schema($boxes);
    }

    private static function fonts(): Section
    {
        $presets = array_combine(array_keys(Branding::FONTS), array_map(static fn (string $preset): string => __("admin.branding.font_names.{$preset}"), array_keys(Branding::FONTS)));
        $boxes = [];

        foreach (array_keys(Branding::FONT_ROLES) as $role) {
            $boxes[] = Select::make("fonts.{$role}")
                ->label(__("admin.branding.font_roles.{$role}"))
                ->options($presets)
                ->placeholder(__('admin.branding.font_default', ['font' => $presets[self::APP_FONTS[$role]]]));
        }

        return Section::make(__('admin.branding.fonts'))
            ->description(__('admin.branding.fonts_hint'))
            ->collapsible()
            ->columns(['default' => 1, 'md' => 3])
            ->schema($boxes);
    }

    private static function sheet(): Section
    {
        return Section::make(__('admin.branding.css'))
            ->description(__('admin.branding.css_hint'))
            ->collapsible()
            ->schema([
                Textarea::make('css')
                    ->hiddenLabel()
                    ->rows(16)
                    ->maxLength(20000)
                    ->extraInputAttributes(['style' => 'font-family: ui-monospace, Consolas, monospace; font-size: 13px;', 'spellcheck' => 'false']),
            ]);
    }

    private static function landing(): Section
    {
        return Section::make(__('admin.branding.landing'))
            ->description(__('admin.branding.landing_hint'))
            ->collapsible()
            ->schema([
                Builder::make('blocks')
                    ->hiddenLabel()
                    ->reorderable()
                    ->collapsible()
                    ->blockNumbers(false)
                    ->maxItems(BrandingForm::MAX_BLOCKS)
                    ->addActionLabel(__('admin.branding.block_add'))
                    ->blocks([
                        self::block('hero', [
                            self::texts('eyebrow', __('admin.branding.fields.eyebrow')),
                            self::texts('title', __('admin.branding.fields.title'), required: true),
                            self::texts('subtitle', __('admin.branding.fields.subtitle'), long: true),
                            ...self::picture(),
                            self::texts('alt', __('admin.branding.fields.alt')),
                        ]),
                        self::block('features', [
                            Repeater::make('items')
                                ->label(__('admin.branding.fields.items'))
                                ->reorderable()
                                ->minItems(1)
                                ->maxItems(BrandingForm::MAX_ITEMS)
                                ->addActionLabel(__('admin.branding.item_add'))
                                ->itemLabel(fn (array $state): ?string => DefinitionForm::text($state['title']['en'] ?? null))
                                ->schema([
                                    self::texts('kicker', __('admin.branding.fields.kicker')),
                                    self::texts('title', __('admin.branding.fields.title'), required: true),
                                    self::texts('text', __('admin.branding.fields.text'), long: true, required: true),
                                ]),
                        ]),
                        self::block('text', [
                            self::texts('title', __('admin.branding.fields.title')),
                            self::texts('body', __('admin.branding.fields.body'), long: true, required: true),
                        ]),
                        self::block('image', [
                            ...self::picture(required: true),
                            self::texts('alt', __('admin.branding.fields.alt'), required: true),
                            self::texts('caption', __('admin.branding.fields.caption')),
                        ]),
                        self::block('cta', [
                            self::texts('title', __('admin.branding.fields.title')),
                            self::texts('text', __('admin.branding.fields.text'), long: true),
                        ]),
                    ]),
            ]);
    }

    private static function share(): Section
    {
        return Section::make(__('admin.branding.share'))
            ->description(__('admin.branding.share_hint'))
            ->collapsible()
            ->schema([
                Group::make([
                    TextInput::make('title')->label(__('admin.branding.share_title'))->maxLength(70),
                    self::texts('description', __('admin.branding.share_description'), long: true),
                    ...self::picture(hint: __('admin.branding.share_image_hint', ['min' => ContentImages::MIN_SIDE, 'max' => ContentImages::MAX_SIDE])),
                ])->statePath('share'),
            ]);
    }

    /**
     * A block of the front page. Its label is the kind of block, and the English title when it has one, so that a collapsed list says which block is which.
     *
     * @param  list<mixed>  $fields
     */
    private static function block(string $type, array $fields): Block
    {
        return Block::make($type)
            ->label(fn (?array $state): string => __("admin.branding.blocks.{$type}").(DefinitionForm::text($state['title']['en'] ?? null) === null ? '' : ': '.DefinitionForm::text($state['title']['en'])))
            ->schema($fields);
    }

    /**
     * A text in every language of the game, in a state of its own (`$field` holds the texts by language). English and Polish are required when the text is, and
     * otherwise once a language has anything in its box.
     */
    private static function texts(string $field, string $label, bool $long = false, bool $required = false): Fieldset
    {
        $limit = BrandingForm::LIMITS[$field] ?? 120;
        $boxes = [];

        foreach (DefinitionForm::LOCALES as $locale) {
            $box = $long ? Textarea::make($locale)->rows($field === 'body' ? 6 : 2) : TextInput::make($locale);
            $box->label(config("ride.locale_names.{$locale}", $locale))->maxLength($limit);

            if (in_array($locale, ['en', 'pl'], true)) {
                $box->required($required ? true : self::hasAText(...));
            }

            $boxes[] = $box;
        }

        return Fieldset::make($label)->statePath($field)->schema($boxes)->columns(1);
    }

    /** Whether any language of the text that the box is a part of has something in it. */
    private static function hasAText(Get $get): bool
    {
        foreach (DefinitionForm::LOCALES as $locale) {
            if (filled($get($locale))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The picture of a block or of the preview of a link: the one the draft names, a box for another one, and a way to take it off. The file is not kept by the box
     * but by the page that saves the draft, which puts its name in `image` (see BrandingPage).
     *
     * @return list<mixed>
     */
    private static function picture(bool $required = false, ?string $hint = null): array
    {
        return [
            Hidden::make('image'),
            View::make('filament.forms.definition-image')->viewData(fn (Get $get): array => ['name' => $get('image')]),
            FileUpload::make('image_upload')
                ->label(__('admin.definitions.image_upload'))
                ->helperText($hint ?? __('admin.definitions.image_hint', ['min' => ContentImages::MIN_SIDE, 'max' => ContentImages::MAX_SIDE]))
                ->storeFiles(false)
                ->acceptedFileTypes(['image/png', 'image/webp', 'image/jpeg'])
                ->maxSize(intdiv(ContentImages::MAX_BYTES, 1024))
                ->required($required ? self::hasNoPicture(...) : false),
            Toggle::make('image_remove')->label(__('admin.definitions.image_remove'))->visible(fn (Get $get): bool => filled($get('image')))->default(false),
        ];
    }

    private static function hasNoPicture(Get $get): bool
    {
        return blank($get('image'));
    }
}
