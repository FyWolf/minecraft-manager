<?php

namespace FyWolf\MinecraftManager\Integrations\Content\Data;

/**
 * One release of a project.
 */
readonly class ContentVersion
{
    /**
     * @param array<int, string>              $gameVersions
     * @param array<int, string>              $loaders
     * @param array<int, ContentFile>         $files
     * @param array<int, ContentDependency>   $dependencies
     */
    public function __construct(
        public string $providerKey,
        public string $id,
        public string $projectId,
        public string $name,
        public ?string $versionNumber = null,
        public array $gameVersions = [],
        public array $loaders = [],
        /** release | beta | alpha */
        public string $channel = 'release',
        public ?string $changelog = null,
        public ?string $publishedAt = null,
        public ?int $downloads = null,
        public array $files = [],
        public array $dependencies = [],
        public bool $featured = false,
    ) {}

    public function primaryFile(): ?ContentFile
    {
        foreach ($this->files as $file) {
            if ($file->primary) {
                return $file;
            }
        }

        return $this->files[0] ?? null;
    }

    /**
     * @return array<int, ContentDependency>
     */
    public function requiredDependencies(): array
    {
        return array_values(array_filter(
            $this->dependencies,
            fn (ContentDependency $dependency) => $dependency->shouldInstall(),
        ));
    }

    public function isInstallable(): bool
    {
        return $this->primaryFile()?->isInstallable() ?? false;
    }

    public function channelIcon(): string
    {
        return match ($this->channel) {
            'alpha' => 'tabler-circle-letter-a',
            'beta' => 'tabler-circle-letter-b',
            default => 'tabler-circle-letter-r',
        };
    }

    public function channelColor(): string
    {
        return match ($this->channel) {
            'alpha' => 'danger',
            'beta' => 'warning',
            default => 'success',
        };
    }
}
