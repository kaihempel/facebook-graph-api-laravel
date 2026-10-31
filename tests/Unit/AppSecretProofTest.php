<?php

declare(strict_types=1);

use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Auth\AppSecretProof;

it('computes the hmac sha256 proof of the access token', function () {
    expect(AppSecretProof::make('token', 'secret'))->toBe(hash_hmac('sha256', 'token', 'secret'))
        ->and(AppSecretProof::make(AccessToken::page('token'), 'secret'))->toBe(hash_hmac('sha256', 'token', 'secret'));
});

it('verifies proofs in constant time', function () {
    $proof = AppSecretProof::make('token', 'secret');

    expect(AppSecretProof::verify('token', 'secret', $proof))->toBeTrue()
        ->and(AppSecretProof::verify('token', 'other', $proof))->toBeFalse();
});
