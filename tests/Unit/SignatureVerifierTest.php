<?php

declare(strict_types=1);

use KaiHempel\MetaGraph\Webhooks\SignatureVerifier;

it('verifies the sha256 signature on the raw body', function () {
    $body = '{"object":"page","entry":[]}';
    $header = 'sha256='.hash_hmac('sha256', $body, 'secret');

    expect(SignatureVerifier::verify($body, $header, 'secret'))->toBeTrue()
        ->and(SignatureVerifier::sign($body, 'secret'))->toBe($header);
});

it('rejects invalid, missing, sha1 or re-serialized signatures', function () {
    $body = '{"object": "page", "entry": []}';
    $reserialized = json_encode(json_decode($body, true));
    $header = SignatureVerifier::sign($body, 'secret');

    expect(SignatureVerifier::verify($body, null, 'secret'))->toBeFalse()
        ->and(SignatureVerifier::verify($body, 'sha1='.hash_hmac('sha1', $body, 'secret'), 'secret'))->toBeFalse()
        ->and(SignatureVerifier::verify($body, $header, 'wrong'))->toBeFalse()
        ->and(SignatureVerifier::verify((string) $reserialized, $header, 'secret'))->toBeFalse()
        ->and(SignatureVerifier::verify($body, $header, ''))->toBeFalse();
});
