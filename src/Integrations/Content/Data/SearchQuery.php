<?php

namespace FyWolf\MinecraftManager\Integrations\Content\Data;

use FyWolf\MinecraftManager\Enums\ContentType;
use FyWolf\MinecraftManager\Enums\ModLoader;

readonly class SearchQuery
{
    public function __construct(
        public ContentType $type,
        public ?ModLoader $loader = null,
        public ?string $gameVersion = null,
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {}

    /**
     * A stable, bounded cache key.
     *
     * Hashed rather than interpolated: the existing plugin appends the raw
     * search term to its key, which lets any visitor mint unbounded cache
     * entries just by typing.
     */
    public function cacheKey(string $providerKey): string
    {
        return 'mcm:' . $providerKey . ':search:' . md5(json_encode([
            $this->type->value,
            $this->loader?->value,
            $this->gameVersion,
            // Normalised so " Sodium " and "sodium" share one entry.
            mb_strtolower(trim((string) $this->search)),
            $this->page,
            $this->perPage,
        ], JSON_THROW_ON_ERROR));
    }
}
