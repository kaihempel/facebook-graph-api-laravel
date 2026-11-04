<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\Auth\TokenType;
use KaiHempel\MetaGraph\Graph\FieldSet;
use KaiHempel\MetaGraph\Graph\ParameterSerializer;

it('serializes graph parameters', function () {
    $date = CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC');

    expect(ParameterSerializer::serialize([
        'message' => 'Hallo',
        'published' => false,
        'temporary' => true,
        'limit' => 25,
        'ratio' => 1.5,
        'scheduled_publish_time' => $date,
        'attached_media' => [['media_fbid' => '1'], ['media_fbid' => '2']],
        'targeting' => ['geo_locations' => ['countries' => ['DE']]],
        'fields' => FieldSet::make('id', 'name'),
        'type' => TokenType::Page,
        'skip' => null,
    ]))->toBe([
        'message' => 'Hallo',
        'published' => 'false',
        'temporary' => 'true',
        'limit' => '25',
        'ratio' => '1.5',
        'scheduled_publish_time' => (string) $date->getTimestamp(),
        'attached_media' => '[{"media_fbid":"1"},{"media_fbid":"2"}]',
        'targeting' => '{"geo_locations":{"countries":["DE"]}}',
        'fields' => 'id,name',
        'type' => 'page',
    ]);
});

it('normalizes dates nested in complex parameters', function () {
    $date = CarbonImmutable::createFromTimestamp(1700000000);

    expect(ParameterSerializer::value(['at' => $date, 'list' => ['ä']]))->toBe('{"at":1700000000,"list":["ä"]}');
});
