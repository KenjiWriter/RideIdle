<?php

declare(strict_types=1);

describe('what the terms and the privacy policy need from the server', function () {
    it('is open to everybody, because the texts are read before there is an account', function () {
        $this->getJson('/api/v1/meta/legal')->assertOk()->assertJsonStructure(['data' => [
            'min_age', 'version', 'guest_expiry_days', 'operator' => ['name', 'address', 'email'],
        ]]);
    });

    it('starts with a careful working age, the version of the texts and nobody named as the operator', function () {
        $this->getJson('/api/v1/meta/legal')->assertOk()->assertExactJson(['data' => [
            'min_age' => 16,
            'version' => '2026-10-23',
            'guest_expiry_days' => 90,
            'operator' => ['name' => null, 'address' => null, 'email' => null],
        ]]);
    });

    it('says what was set', function () {
        config(['ride.legal' => [
            'min_age' => 13,
            'version' => '2027-01-15',
            'operator' => ['name' => 'Ride Idle Studio', 'address' => 'ul. Przykładowa 1, 00-001 Warszawa', 'email' => 'legal@ride.example'],
        ]]);

        $this->getJson('/api/v1/meta/legal')->assertOk()->assertExactJson(['data' => [
            'min_age' => 13,
            'version' => '2027-01-15',
            'guest_expiry_days' => 90,
            'operator' => ['name' => 'Ride Idle Studio', 'address' => 'ul. Przykładowa 1, 00-001 Warszawa', 'email' => 'legal@ride.example'],
        ]]);
    });

    it('reports a detail that is empty as not set', function () {
        config(['ride.legal.operator' => ['name' => '', 'address' => '   ', 'email' => null]]);

        $this->getJson('/api/v1/meta/legal')->assertOk()->assertJsonPath('data.operator', ['name' => null, 'address' => null, 'email' => null]);
    });

    it('says how long a guest account is kept, from the setting that removes it', function () {
        config(['ride.guest_expiry_days' => 45]);

        expect($this->getJson('/api/v1/meta/legal')->json('data.guest_expiry_days'))->toBe(45);
    });

    it('gives the age as a number', function () {
        config(['ride.legal.min_age' => '16']);

        expect($this->getJson('/api/v1/meta/legal')->json('data.min_age'))->toBe(16);
    });
});
