<?php

namespace FyWolf\MinecraftManager\Integrations\Content\Data;

use FyWolf\MinecraftManager\Enums\ContentType;

/**
 * A searchable project, normalised across providers.
 */
readonly class ContentProject
{
    public function __construct(
        public string $providerKey,
        public string $id,
        public string $title,
        public ?string $slug = null,
        public ?string $summary = null,
        public ?string $author = null,
        public ?string $iconUrl = null,
        public ?int $downloads = null,
        public ?string $updatedAt = null,
        public ?string $url = null,
        public ?ContentType $type = null,
        /** CurseForge exposes this per project; false means no file can be fetched by API. */
        public bool $distributionAllowed = true,
    ) {}

    /**
     * Filament tables built from `->records()` receive plain arrays, so every
     * DTO that reaches a table has to be able to flatten itself.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerKey,
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'author' => $this->author,
            'icon_url' => $this->iconUrl,
            'downloads' => $this->downloads,
            'updated_at' => $this->updatedAt,
            'url' => $this->url,
            'type' => $this->type?->value,
            'distribution_allowed' => $this->distributionAllowed,
        ];
    }
}
