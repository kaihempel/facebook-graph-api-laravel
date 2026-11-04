<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use KaiHempel\MetaGraph\AppConfig;
use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Auth\TokenType;
use KaiHempel\MetaGraph\Exceptions\ConfigurationException;
use KaiHempel\MetaGraph\Facades\MetaGraph;
use KaiHempel\MetaGraph\Support\Redactor;

it('creates typed tokens', function () {
    expect(AccessToken::user('u')->type)->toBe(TokenType::User)
        ->and(AccessToken::page('p')->type)->toBe(TokenType::Page)
        ->and(AccessToken::systemUser('s')->type)->toBe(TokenType::SystemUser)
        ->and(AccessToken::app('1', 'secret')->value)->toBe('1|secret')
        ->and(AccessToken::app('1', 'secret')->type)->toBe(TokenType::App);
});

it('knows when it expires', function () {
    CarbonImmutable::setTestNow('2026-10-31 12:00:00');

    $token = AccessToken::user('u', now()->addDays(5));

    expect($token->isExpired())->toBeFalse()
        ->and($token->expiresWithin(7 * 86400))->toBeTrue()
        ->and($token->expiresWithin(86400))->toBeFalse()
        ->and($token->isExpired(now()->addDays(6)))->toBeTrue()
        ->and(AccessToken::page('p')->isExpired())->toBeFalse()
        ->and(AccessToken::fromOAuthResponse(['access_token' => 'x', 'expires_in' => 0])->expiresAt)->toBeNull();

    CarbonImmutable::setTestNow();
});

it('never leaks the token value in string or json form', function () {
    $token = AccessToken::user('EAAGverysecrettokenvalue', scopes: ['pages_show_list']);

    expect((string) $token)->not->toContain('verysecret')
        ->and(json_encode($token))->not->toContain('verysecret')
        ->and($token->toArray()['value'])->toBe('EAAGverysecrettokenvalue')
        ->and($token->masked())->toStartWith('EAAGve');
});

it('maps debug token types and rate limit logic', function () {
    expect(TokenType::fromGraph('PAGE'))->toBe(TokenType::Page)
        ->and(TokenType::fromGraph('USER'))->toBe(TokenType::User)
        ->and(TokenType::fromGraph('APP'))->toBe(TokenType::App)
        ->and(TokenType::fromGraph('SYSTEM_USER'))->toBe(TokenType::SystemUser)
        ->and(TokenType::fromGraph(null))->toBeNull()
        ->and(TokenType::Page->usesBusinessUseCaseLimits())->toBeTrue()
        ->and(TokenType::SystemUser->usesBusinessUseCaseLimits())->toBeTrue()
        ->and(TokenType::User->usesBusinessUseCaseLimits())->toBeFalse();
});

it('redacts sensitive values', function () {
    expect(Redactor::mask(''))->toBe('')
        ->and(Redactor::mask('short'))->toBe('*****')
        ->and(Redactor::redact(['access_token' => 'EAAGsecretvalue', 'nested' => ['client_secret' => 'abcdefghijk'], 'appsecret_proof' => ['x'], 'message' => 'ok']))
        ->toBe(['access_token' => 'EAAGse…****', 'nested' => ['client_secret' => 'abcdef…****'], 'appsecret_proof' => '[redacted]', 'message' => 'ok'])
        ->and(Redactor::url('https://graph.facebook.com/me?fields=id&access_token=EAAGsecretvalue&x=1'))
        ->toBe('https://graph.facebook.com/me?fields=id&access_token=EAAGse…****&x=1');
});

it('validates app configuration', function () {
    $app = AppConfig::fromArray('main', ['app_id' => 1, 'app_secret' => 's', 'scopes' => ['a', 5, 'b'], 'redirect' => '']);

    expect($app->appId)->toBe('1')
        ->and($app->scopes)->toBe(['a', 'b'])
        ->and($app->redirect)->toBeNull()
        ->and(fn () => AppConfig::fromArray('x', ['app_id' => null]))->toThrow(ConfigurationException::class);
});

it('fails for unknown apps', function () {
    MetaGraph::app('nope')->asApp();
})->throws(ConfigurationException::class, 'Meta app [nope] is not configured.');
