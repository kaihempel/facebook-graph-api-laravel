<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Media;

use Illuminate\Http\UploadedFile;
use KaiHempel\MetaGraph\Contracts\MediaSource as MediaSourceContract;

/**
 * Fabrik für Medienquellen: URL, lokale Datei, Laravel-Disk oder Upload.
 */
abstract class MediaSource implements MediaSourceContract
{
    /**
     * Gehostete Datei. Meta lädt sie selbst (Hosts mit robots.txt-Sperre werden abgelehnt).
     */
    public static function url(string $url, ?string $fileName = null, ?string $mimeType = null, ?int $size = null): UrlSource
    {
        return new UrlSource($url, $fileName, $mimeType, $size);
    }

    public static function file(string $path, ?string $fileName = null, ?string $mimeType = null): FileSource
    {
        return new FileSource($path, $fileName, $mimeType);
    }

    /**
     * Datei auf einer Laravel-Disk. Wird gestreamt, ohne den Inhalt komplett in den Speicher zu laden.
     */
    public static function disk(string $disk, string $path): DiskSource
    {
        return new DiskSource($disk, $path);
    }

    public static function contents(string $contents, string $fileName = 'media', string $mimeType = 'application/octet-stream'): StringSource
    {
        return new StringSource($contents, $fileName, $mimeType);
    }

    /**
     * Hochgeladene Datei aus einem Request. Temporäre Uploads existieren nur während
     * des Requests; für Queue-Jobs die Datei vorher speichern und MediaSource::disk() nutzen.
     */
    public static function upload(UploadedFile $file): FileSource
    {
        return new FileSource(
            path: (string) $file->getRealPath(),
            fileName: $file->getClientOriginalName() ?: $file->getFilename(),
            mimeType: $file->getClientMimeType() ?: null,
        );
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function remoteUrl(): ?string
    {
        return null;
    }

    public function read(int $offset, int $length): string
    {
        $stream = $this->openStream();

        try {
            $meta = stream_get_meta_data($stream);

            if ($meta['seekable']) {
                fseek($stream, $offset);
            } else {
                $skip = $offset;
                while ($skip > 0 && ! feof($stream)) {
                    $chunk = fread($stream, max(1, min($skip, 1024 * 1024)));
                    if ($chunk === false) {
                        break;
                    }
                    $skip -= strlen($chunk);
                }
            }

            $buffer = '';
            while (strlen($buffer) < $length && ! feof($stream)) {
                $chunk = fread($stream, max(1, $length - strlen($buffer)));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer .= $chunk;
            }

            return $buffer;
        } finally {
            fclose($stream);
        }
    }
}
