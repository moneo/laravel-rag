<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Moneo\LaravelRag\Schema\VectorBlueprint;

test('vector macro is registered and callable', function () {
    VectorBlueprint::register();

    expect(Blueprint::hasMacro('vector'))->toBeTrue()
        ->and(Blueprint::hasMacro('vectorIndex'))->toBeTrue()
        ->and(Blueprint::hasMacro('fulltextIndex'))->toBeTrue();
});

test('register is idempotent', function () {
    VectorBlueprint::register();
    VectorBlueprint::register();

    expect(Blueprint::hasMacro('vector'))->toBeTrue();
});

