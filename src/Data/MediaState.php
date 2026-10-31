<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Data;

enum MediaState: string
{
    case Uploading = 'uploading';
    case UploadComplete = 'upload_complete';
    case UploadFailed = 'upload_failed';
    case Processing = 'processing';
    case Ready = 'ready';
    case Error = 'error';
    case Expired = 'expired';

    public static function fromGraph(?string $status): self
    {
        return self::tryFrom(strtolower((string) $status)) ?? match (strtolower((string) $status)) {
            'published', 'complete' => self::Ready,
            'in_progress', 'not_started' => self::Processing,
            default => self::Uploading,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Ready, self::Error, self::Expired, self::UploadFailed], true);
    }

    public function isFailed(): bool
    {
        return in_array($this, [self::Error, self::Expired, self::UploadFailed], true);
    }
}
