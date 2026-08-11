<?php

namespace FyWolf\MinecraftManager\Support;

/**
 * One file a modpack expects on the server.
 */
class PackEntry
{
    /**
     * @param array<int, string>    $urls
     * @param array<string, string> $hashes
     */
    public function __construct(
        public string $path,
        public array $urls = [],
        public ?int $size = null,
        public array $hashes = [],
        public bool $required = true,
        /**
         * False when the file cannot be fetched through an API at all — the
         * CurseForge third-party-distribution opt-out. Counted during parsing
         * so the user is told before the install commits, not at file 140.
         */
        public bool $distributionAllowed = true,
        public ?string $browserUrl = null,
        /** CurseForge only, until the batch resolve fills in the URL. */
        public ?int $projectId = null,
        public ?int $fileId = null,
    ) {}

    public function url(): ?string
    {
        return $this->urls[0] ?? null;
    }

    public function isInstallable(): bool
    {
        return $this->distributionAllowed && filled($this->url());
    }

    public function filename(): string
    {
        return basename($this->path);
    }

    public function directory(): string
    {
        $directory = trim(dirname($this->path), '/.');

        return $directory === '' ? '/' : '/' . $directory;
    }
}
