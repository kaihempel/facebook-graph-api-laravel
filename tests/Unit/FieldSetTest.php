<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Graph\FieldSet;

it('serializes simple fields', function () {
    expect(FieldSet::make('id', 'name', 'fan_count')->toString())->toBe('id,name,fan_count');
});

it('serializes nested field expansion with modifiers as documented', function () {
    $fields = FieldSet::make('id', 'message', 'created_time')
        ->expand('comments', FieldSet::make('id', 'message', 'from')->limit(25)->summary())
        ->expand('attachments', FieldSet::make('media_type', 'url'));

    expect((string) $fields)->toBe(
        'id,message,created_time,comments.limit(25).summary(true){id,message,from},attachments{media_type,url}',
    );
});

it('supports aliases and expansion without sub fields', function () {
    $fields = FieldSet::make('id')
        ->expand('picture', FieldSet::make()->modifier('width', 100)->as('small'))
        ->expand('picture', FieldSet::make()->modifier('width', 720)->as('large'));

    expect($fields->toString())->toBe('id,picture.width(100).as(small),picture.width(720).as(large)');
});

it('supports deeply nested expansion and array input', function () {
    $fields = FieldSet::make('id')->expand('posts', FieldSet::make('id')->limit(5)->expand('comments', ['message']));

    expect($fields->toString())->toBe('id,posts.limit(5){id,comments{message}}');
});

it('creates field sets from strings, arrays and null', function () {
    expect(FieldSet::from('id, name')->names())->toBe(['id', 'name'])
        ->and(FieldSet::from(['id'])->toString())->toBe('id')
        ->and(FieldSet::from(null)->isEmpty())->toBeTrue()
        ->and(FieldSet::make('id')->add('name')->toString())->toBe('id,name')
        ->and(FieldSet::make('a')->summary(false)->expand('b')->toString())->toBe('a,b');
});

it('is immutable', function () {
    $base = FieldSet::make('id');
    $base->add('name');
    $base->limit(5);

    expect($base->toString())->toBe('id');
});
