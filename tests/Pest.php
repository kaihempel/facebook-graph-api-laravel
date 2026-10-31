<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Contract', 'Live');

/**
 * Lädt eine JSON-Fixture aus tests/Fixtures.
 *
 * @return array<array-key, mixed>
 */
function fixture(string $name): array
{
    $content = file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');

    return json_decode((string) $content, true, flags: JSON_THROW_ON_ERROR);
}
