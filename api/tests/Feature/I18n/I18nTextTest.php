<?php

declare(strict_types=1);

use App\Casts\I18nTextCast;
use App\Models\User;
use App\Support\I18nText;

it('normalizes keys and drops empty translations', function () {
    $text = I18nText::from(['PT_br' => 'Carro', 'en' => 'Car', 'fr' => '  ', 'xx' => 'ignored']);

    expect($text->all())->toBe(['pt-BR' => 'Carro', 'en' => 'Car']);
});

it('falls back to English, then Polish, then the first available text', function () {
    expect(I18nText::from(['en' => 'Car', 'pl' => 'Auto'])->for('fr'))->toBe('Car')
        ->and(I18nText::from(['pl' => 'Auto'])->for('de'))->toBe('Auto')
        ->and(I18nText::from(['fr' => 'Voiture'])->for('de'))->toBe('Voiture')
        ->and(I18nText::from(null)->for('de'))->toBe('')
        ->and(I18nText::from(['pl' => 'Auto', 'de' => 'Auto (DE)'])->for('de'))->toBe('Auto (DE)');
});

it('uses the current application locale by default', function () {
    app()->setLocale('pt_BR');

    expect((string) I18nText::from(['en' => 'Car', 'pt-BR' => 'Carro']))->toBe('Carro');
});

it('treats a plain string as the default language text', function () {
    expect(I18nText::from('Car')->all())->toBe(['en' => 'Car']);
});

it('reports missing languages and completeness', function () {
    $text = I18nText::from(['en' => 'Car', 'pl' => 'Auto']);

    expect($text->missing())->toBe(['fr', 'de', 'pt-BR'])
        ->and($text->isComplete())->toBeTrue()
        ->and(I18nText::from(['en' => 'Car'])->isComplete())->toBeFalse();
});

it('round-trips through the Eloquent cast', function () {
    $cast = new I18nTextCast();
    $model = new User();

    $json = $cast->set($model, 'name', ['en' => 'Car', 'pl' => 'Auto', 'pt_BR' => 'Carro'], []);

    expect($json)->toBe('{"en":"Car","pl":"Auto","pt-BR":"Carro"}')
        ->and($cast->get($model, 'name', $json, [])->for('pt-BR'))->toBe('Carro')
        ->and($cast->get($model, 'name', null, [])->all())->toBe([]);
});
