<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Contracts;

/**
 * Quelle für Fotos, Videos und Reels.
 *
 * Remote-Quellen (URL) werden über die URL-Varianten der API übergeben,
 * ohne die Datei auf den eigenen Server zu laden.
 */
interface MediaSource
{
    public function isRemote(): bool;

    /**
     * HTTPS-URL bei Remote-Quellen, sonst null.
     */
    public function remoteUrl(): ?string;

    public function fileName(): string;

    public function mimeType(): string;

    /**
     * Dateigröße in Bytes.
     */
    public function size(): int;

    /**
     * Öffnet einen lesbaren Stream (ohne den ganzen Inhalt in den Speicher zu laden).
     *
     * @return resource
     */
    public function openStream();

    /**
     * Liest einen Ausschnitt der Datei.
     */
    public function read(int $offset, int $length): string;
}
