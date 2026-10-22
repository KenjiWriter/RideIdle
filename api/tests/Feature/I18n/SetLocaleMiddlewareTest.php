<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::middleware('api')->get('/api/_test/fuel', fn () => response()->json(['message' => __('game.fuel.empty')]));
});

it('uses the default language when nothing is requested', function () {
    $this->getJson('/api/_test/fuel')
        ->assertOk()
        ->assertHeader('Content-Language', 'en')
        ->assertJson(['message' => 'The tank is empty.']);
});

it('follows Accept-Language', function (string $header, string $message, string $language) {
    $this->getJson('/api/_test/fuel', ['Accept-Language' => $header])
        ->assertOk()
        ->assertHeader('Content-Language', $language)
        ->assertJson(['message' => $message]);
})->with([
    ['pl-PL,pl;q=0.9', 'Bak jest pusty.', 'pl'],
    ['fr-FR,fr;q=0.9,en;q=0.8', 'Le réservoir est vide.', 'fr'],
    ['de-DE', 'Der Tank ist leer.', 'de'],
    ['pt-BR,pt;q=0.9', 'O tanque está vazio.', 'pt-BR'],
    ['es-ES', 'The tank is empty.', 'en'],
]);

it('accepts br as an alias of Brazilian Portuguese', function () {
    $this->getJson('/api/_test/fuel', ['X-Locale' => 'br'])
        ->assertHeader('Content-Language', 'pt-BR')
        ->assertJson(['message' => 'O tanque está vazio.']);
});

it('prefers X-Locale over Accept-Language', function () {
    $this->getJson('/api/_test/fuel', ['X-Locale' => 'de', 'Accept-Language' => 'fr'])
        ->assertHeader('Content-Language', 'de')
        ->assertJson(['message' => 'Der Tank ist leer.']);
});

it('uses the language saved on the account when no X-Locale is sent', function () {
    Sanctum::actingAs(User::factory()->create(['locale' => 'pl']));

    $this->getJson('/api/_test/fuel', ['Accept-Language' => 'fr'])
        ->assertHeader('Content-Language', 'pl')
        ->assertJson(['message' => 'Bak jest pusty.']);
});

it('marks responses as varying by language headers', function () {
    expect($this->getJson('/api/_test/fuel')->headers->get('Vary'))
        ->toContain('Accept-Language')
        ->toContain('X-Locale');
});

it('lists the supported languages', function () {
    $this->getJson('/api/v1/meta/locales', ['Accept-Language' => 'fr'])
        ->assertOk()
        ->assertJsonPath('default', 'en')
        ->assertJsonPath('current', 'fr')
        ->assertJsonCount(5, 'data')
        ->assertJsonFragment(['code' => 'pt-BR', 'name' => 'Português (Brasil)']);
});
