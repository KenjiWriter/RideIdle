# Notes of forms-branding-etc

Files written (x5 locales pl, en, fr, de, pt_BR): `api/lang/<locale>/help/` branding.php, vocabulary.php, rules_text.php, studio.php, model.php, guide.forms-branding-etc.php.
Totals per locale: 60 entries (branding 40, vocabulary 4, rules_text 2, studio 3, model 6, guide 5) and 102 option lines (fonts 3 x 6, vocabulary.area 44, rules_text.document 2, studio.section 24, model.role 8, model.stat 6). `php -l` clean in all 30 files. A standalone checker (no framework, not the test suite) is at `help-notes/check-forms-branding-etc.php`: same keys, options, steps count, placeholder words (:min :max :default :limit) and French typography (NBSP before : ; ? ! and inside « », no straight apostrophe) are OK.

## Sources used
- branding colours: `web/src/style.css` (@theme, :root skin mixes, component rules), `BrandingFields::APP_COLORS`, Tailwind class use in `web/src/**/*.vue`; rarity names from `web/src/i18n/locales/*.json`.
- branding fonts: `Branding::FONTS`, `FONT_ROLES`, `font-display|sans|mono` in style.css.
- branding css: `CssPolicy` (read in full), `Branding::stylesheet()`, `BrandingForm`, `branding.schema.json`.
- landing blocks: `BrandingForm`, `LandingBlocks.vue`, `LandingView.vue`, `landing.ts`, `BrandingController::landing`, schema (limits, 12 blocks, 6 cards).
- link preview: `BrandingController::share`, `SharePage`, `ContentImages` (PNG/WebP/JPEG, 1 MB, 32-2048 px), `BrandingPage::withPicture`.
- model: `GameModel`, `ModelForm`, `ModelFields`, `model.schema.json`, `content/rules/model.json`, `PackValidator::checkModelDocument/checkModel`, `docs/api-runs.md` (L3a roles section), `web/src/features/car/score.ts`, stat formulas from `docs/api-runs.md` line 165.
- vocabulary: `VocabularyPage`, `VocabularyForm` (30 shown, apply rules), `Vocabulary` (fallback to the pack's English), `PackValidator::checkVocabulary`, `MessageCatalog`, `ServerMessages`, area keys from `web/src/i18n/locales/pl.json`.
- rules_text: `RulesJsonPage`, `RulesDocumentPage::keep`, `DefinitionUsage::removedInUse`.
- studio: `StudioPage`, `StudioFields`, `SimpleMode`, `config/ride.php` studio.simple (39 patterns), `admin.studio.sections|effects` (pl/en) for the section lines, `starter.json` (fuel 200 cents).

## Entries I wrote that are NOT in the input JSON
The survey runs on an empty Branding form (no blocks), so it never saw the keys that `BrandingFields` uses once a block exists. They are used in code (`helpKey` / `FieldHelp::apply`) and I wrote them in all five locales: `branding.items`, `branding.image_upload` (the block picture; the preview one is `share_image_upload`), `branding.text_eyebrow`, `text_title`, `text_subtitle`, `text_alt`, `text_kicker`, `text_text`, `text_body`, `text_caption` (`text_description` was in the input). `text_*` entries take `:limit` (120 / 400 / 4000 characters). `studio.section` also has the 7 sections that only the advanced mode shows (engines, drivetrain, affixes, tire_matrix, weather, regions, roads), which the input lacks because it was taken in the simple mode. The survey should be run once with a draft that has all five block kinds, so that the catalog test sees these keys.

## UNSURE
- branding.css (tip): how an operator finds the 64-hex name of a picture for `url(/api/v1/media/<name>)`; the panel shows the picture but I did not find where it shows the name. The tip only says the picture must already be in the store and what the name looks like.
- branding.color_*: "where each colour is used" is read from style.css and the Tailwind classes, not from a screen-by-screen check; the list per colour may miss a place. The skin still has hard-coded colours (about 140, as memory says), so the tip of `color_faint` warns that some details do not follow.
- branding.text_description: the preview takes the text in the game's own language (`Game::locale`) or English; so the other languages of that box are not read. Established from `BrandingController::share`, not tried in a browser.
- branding.share_image_upload: the 1200 x 630 size is the old panel hint (a usual size for a preview), not a rule of the code.
- vocabulary.area options: the one-line help of the areas garage, condition, police, fleet, market, awayAccount, story, tutorial, run, car, part, workshop is inferred from the key names and the first keys of each area, not from reading every key.
- studio.simple: "about 750 numbers" is the brief's figure; I did not count the simple-mode share.
- model.role cooling/boost/gearbox: taken from the roles paragraph of `docs/api-runs.md` ("chłodnica doładowania i radiator", "przyrost mocy, lag i obciążenie silnika", "limit prędkości i start"); how exactly each reads is not re-verified in `RunInputsFactory`.
- guide.branding example: illustrative values (no branding document with colours exists in `content/`; `content/themes/ships.json` has no colours).

## Inconsistencies worth knowing
1. `cyan_bg` is named like a background, but style.css only uses it as the dark TEXT colour on cyan plates (the class-tier plate in the top bar, the clock of a queued job). I labelled it "Dark shade of the second accent" and said so in the help. `amber_bg` is the "You" row of the race table and the second colour of the hazard stripe.
2. `CssPolicy::MAX_BYTES` is 20000 BYTES, the form field has `maxLength(20000)` CHARACTERS: a sheet with accents can pass the box and still be refused (2-4 bytes per non-ASCII letter). The range line says bytes.
3. `vocabulary.value` is applied with `label: false, help: false`, so only its `tip` shows, as a tooltip icon on every box (5 languages x up to 30 messages = 150 icons). Everything important about the boxes is in the tip; consider showing it once, or moving it into the guide.
4. `vocabulary.area` (44 options) and `studio.section` (24 options) are built by the pages with counts and `FieldHelp::options()` is not used for them, so my option labels only show as bullets in the helper text under the field: 44 bullets under the Area box and 24 under the Section box. The brief asked for every option; you may want `FieldHelp::html` to skip `options` for these two (`HELP_DYNAMIC_CHOICES` already lists them).
5. Vocabulary behaviour that surprises people: once a message is changed in any language, a language left empty shows the pack's ENGLISH text, not the app's own text in that language (a French player sees English for a message you changed only in Polish). Said in `vocabulary.value` tip, the guide and the Area help.
6. In `StudioPage` the Simple-mode and Sliders toggles also call `fillForm()` (redraw from the saved draft), so they throw away unsaved changes just as the section does; only `admin.studio.section_hint` says it for the section. The help of all three says it.
7. `branding.text_title` / `text_text` are one entry for several places (hero title, card title, text/cta heading; card text, cta sentence): the help describes each use in one line.
8. `image_remove` together with a chosen file: the file wins (`BrandingPage::withPicture` removes, then stores the upload). In the Picture block, taking the picture off gives a schema refusal (image is required there).
9. Names of pages in the other languages follow `lang/<locale>/admin.php` nav labels (fr "Studio de paramètres", de "Wortschatz", en "Model of the game" ...). Another writer's `en/help/part.php` says 'the "Game model" page'; the nav label is "Model of the game".
10. `admin.branding.color_names`, `font_roles` and `fields` (old labels in admin.php) are no longer read by `BrandingFields` for the colours and fonts (the catalog supplies the labels); only the block labels and `fields.*` are still used.
