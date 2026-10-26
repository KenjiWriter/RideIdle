<?php

declare(strict_types=1);

use App\Content\RarityPace;

/** A folder for the tables of a test, with the real generator kept (the rules it reads are the ones of the repository). */
function rarityPaceTemporaryContent(): string
{
    $pace = app(RarityPace::class);
    app()->instance(RarityPace::class, $pace);

    $folder = sys_get_temp_dir().'/ride-rarity-pace-'.bin2hex(random_bytes(4));
    config(['ride.content_path' => $folder]);

    return $folder;
}

it('confirms that the committed table of the pace of the rarities is up to date', function () {
    $this->artisan('economy:rarity-pace', ['--check' => true])
        ->expectsOutputToContain('The table of the pace of the rarities is up to date.')
        ->assertSuccessful();
});

it('fails the check when the table is missing', function () {
    rarityPaceTemporaryContent();

    $this->artisan('economy:rarity-pace', ['--check' => true])
        ->expectsOutputToContain('missing or stale')
        ->assertFailed();
});

it('fails the check when the table is not what the pipeline computes now', function () {
    $folder = rarityPaceTemporaryContent();
    mkdir("{$folder}/tables", 0777, true);

    $table = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/content/tables/rarity-pace.json'), true, 512, JSON_THROW_ON_ERROR);
    $table['gain_by_crates'][10] += 0.01;
    file_put_contents("{$folder}/tables/rarity-pace.json", json_encode($table));

    $this->artisan('economy:rarity-pace', ['--check' => true])->assertFailed();

    file_put_contents("{$folder}/tables/rarity-pace.json", '{not json');

    $this->artisan('economy:rarity-pace', ['--check' => true])->assertFailed();
});

it('writes the table where the content is, and it is the one that is committed', function () {
    $folder = rarityPaceTemporaryContent();

    $this->artisan('economy:rarity-pace')->expectsOutputToContain('Wrote')->assertSuccessful();

    $written = json_decode((string) file_get_contents("{$folder}/tables/rarity-pace.json"), true, 512, JSON_THROW_ON_ERROR);
    $committed = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/content/tables/rarity-pace.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($written)->toEqual($committed);

    $this->artisan('economy:rarity-pace', ['--check' => true])->assertSuccessful();
});
