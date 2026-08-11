<?php

namespace FyWolf\MinecraftManager\Integrations\Content\Data;

/**
 * One downloadable file belonging to a version.
 */
readonly class ContentFile
{
    /**
     * @param array<string, string> $hashes
     */
    public function __construct(
        public ?string $url,
        public string $filename,
        public ?int $size = null,
        public array $hashes = [],
        public bool $primary = true,
        /**
         * False when the author opted out of third-party distribution.
         *
         * A first-class field rather than an `is_null($url)` check scattered
         * across call sites, because the modpack installer has to count these
         * during parsing — before it commits to an install — so it can tell the
         * user how many files they will have to fetch by hand. Discovering it at
         * file 140 of 187 is the difference between an annoyance and a ticket.
         */
        public bool $distributionAllowed = true,
        /** Where to send the user when we cannot fetch it for them. */
        public ?string $browserUrl = null,
    ) {}

    public function isInstallable(): bool
    {
        return $this->distributionAllowed && filled($this->url);
    }
}
