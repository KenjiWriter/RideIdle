<?php

declare(strict_types=1);

/*
| The game rules live in App\Domain and must stay pure: no framework, no database, no clock,
| no randomness. That is what makes settlement reproducible on every machine and database.
*/

arch('the domain does not depend on the framework, the database or the HTTP layer')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate',
        'Carbon',
        'App\Models',
        'App\Http',
        'App\Console',
        'App\Providers',
        'Laravel',
    ]);

arch('the domain reads no clock, no randomness and no configuration')
    ->expect('App\Domain')
    ->not->toUse([
        'time',
        'microtime',
        'hrtime',
        'date',
        'gmdate',
        'strtotime',
        'now',
        'today',
        'rand',
        'mt_rand',
        'random_int',
        'shuffle',
        'config',
        'env',
        'app',
        'request',
        'DateTime',
        'DateTimeImmutable',
    ]);

arch('domain classes are final and use strict types')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal()
    ->toUseStrictTypes();

arch('domain value objects are immutable')
    ->expect([
        'App\Domain\Settlement\RunState',
        'App\Domain\Settlement\RunConfig',
        'App\Domain\Settlement\SettlementEvent',
        'App\Domain\Settlement\SettlementResult',
        'App\Domain\Settlement\PayoutResult',
        'App\Domain\Settlement\EloTable',
    ])
    ->toBeReadonly();
