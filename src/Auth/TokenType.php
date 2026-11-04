<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Auth;

enum TokenType: string
{
    case User = 'user';
    case Page = 'page';
    case App = 'app';
    case SystemUser = 'system_user';

    /**
     * Übersetzt den Typ aus /debug_token (USER, PAGE, APP, SYSTEM_USER).
     */
    public static function fromGraph(?string $type): ?self
    {
        return match (strtoupper((string) $type)) {
            'USER' => self::User,
            'PAGE' => self::Page,
            'APP' => self::App,
            'SYSTEM_USER', 'SYSTEMUSER' => self::SystemUser,
            default => null,
        };
    }

    /**
     * Rate-Limit-Logik laut Meta: User- und App-Token unterliegen den
     * Platform Limits, Page- und System-User-Token den Business Use Case Limits.
     */
    public function usesBusinessUseCaseLimits(): bool
    {
        return $this === self::Page || $this === self::SystemUser;
    }
}
