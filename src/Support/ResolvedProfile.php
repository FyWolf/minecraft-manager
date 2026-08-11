<?php

namespace FyWolf\MinecraftManager\Support;

use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Enums\ContentType;
use FyWolf\MinecraftManager\Enums\ModLoader;
use FyWolf\MinecraftManager\Models\CapabilityProfile;

/**
 * A server's resolved capabilities, however they were arrived at.
 *
 * Every consumer sees this one type whether the profile came from an explicit
 * admin mapping, was inherited from a parent egg, or was guessed — so no page
 * ever has to branch on where its configuration came from. Only the header
 * banner cares, via $source.
 */
readonly class ResolvedProfile
{
    public const SOURCE_EXPLICIT = 'explicit';

    public const SOURCE_INHERITED = 'inherited';

    public const SOURCE_HEURISTIC = 'heuristic';

    /**
     * @param array<int, Capability> $capabilities
     * @param array<int, string>     $mcVersionVariables
     * @param array<int, string>     $loaderVersionVariables
     * @param array<int, string>     $configFiles
     */
    public function __construct(
        public string $name,
        public ?ModLoader $loader,
        public array $capabilities,
        public ?string $contentDir,
        public ?string $worldsDir,
        public string $dimensionLayout,
        public ?string $versionProvider,
        public ?string $jarPath,
        public array $mcVersionVariables,
        public array $loaderVersionVariables,
        public array $configFiles,
        public string $source,
        public ?string $sourceEggName = null,
        public ?int $profileId = null,
    ) {}

    public static function fromModel(CapabilityProfile $profile, string $source = self::SOURCE_EXPLICIT, ?string $sourceEggName = null): self
    {
        return new self(
            name: $profile->name,
            loader: $profile->loader(),
            capabilities: self::mapCapabilities($profile->capabilities ?? []),
            contentDir: $profile->content_dir,
            worldsDir: $profile->worlds_dir,
            dimensionLayout: $profile->dimension_layout ?: 'vanilla',
            versionProvider: $profile->version_provider,
            jarPath: $profile->jar_path,
            mcVersionVariables: $profile->mc_version_variables ?? [],
            loaderVersionVariables: $profile->loader_version_variables ?? [],
            configFiles: $profile->config_files ?? [],
            source: $source,
            sourceEggName: $sourceEggName,
            profileId: $profile->id,
        );
    }

    /**
     * Build from the built-in defaults in config, for a heuristically detected
     * loader. Deliberately produces no database row — persisting a guess would
     * fight the administrator the next time they edited the real mapping.
     */
    public static function fromDefaults(ModLoader $loader): ?self
    {
        $defaults = config('minecraft-manager.profiles.' . $loader->value);

        if (! is_array($defaults)) {
            return null;
        }

        return new self(
            name: $defaults['name'] ?? $loader->getLabel(),
            loader: $loader,
            capabilities: self::mapCapabilities($defaults['capabilities'] ?? []),
            contentDir: $defaults['content_dir'] ?? null,
            worldsDir: $defaults['worlds_dir'] ?? '/',
            dimensionLayout: $defaults['dimension_layout'] ?? $loader->dimensionLayout(),
            versionProvider: $defaults['version_provider'] ?? null,
            jarPath: $defaults['jar_path'] ?? null,
            mcVersionVariables: $defaults['mc_version_variables'] ?? [],
            loaderVersionVariables: $defaults['loader_version_variables'] ?? [],
            configFiles: $defaults['config_files'] ?? [],
            source: self::SOURCE_HEURISTIC,
        );
    }

    /**
     * @param array<int, string> $values
     *
     * @return array<int, Capability>
     */
    private static function mapCapabilities(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => Capability::tryFrom($value),
            $values,
        )));
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function hasAny(Capability ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->has($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which content types this server can browse, in display order.
     *
     * This is what makes one ContentBrowserPage serve every loader: a Paper
     * server yields [Plugin], a Fabric server yields [Mod, Modpack], and a
     * Vanilla server yields [] so the page never renders at all.
     *
     * @return array<int, ContentType>
     */
    public function contentTypes(): array
    {
        $types = [];

        foreach ([ContentType::Mod, ContentType::Plugin, ContentType::Modpack, ContentType::ResourcePack, ContentType::Datapack] as $type) {
            if ($this->has($type->capability())) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The content types that belong in the browser (packs get their own page).
     *
     * @return array<int, ContentType>
     */
    public function browsableContentTypes(): array
    {
        return array_values(array_filter(
            $this->contentTypes(),
            fn (ContentType $type) => $type !== ContentType::Modpack,
        ));
    }

    /**
     * Bukkit-family servers scatter a world across sibling folders
     * (world, world_nether, world_the_end); everything else nests them inside
     * the world directory.
     */
    public function hasSiblingDimensions(): bool
    {
        return $this->dimensionLayout === 'bukkit';
    }

    public function jarPath(): string
    {
        return $this->jarPath ?: (string) config('minecraft-manager.versions.default_jar', 'server.jar');
    }

    /**
     * A one-line explanation of where this configuration came from, shown in the
     * page header so an administrator can tell a guess from a decision.
     */
    public function sourceDescription(): string
    {
        return match ($this->source) {
            self::SOURCE_EXPLICIT => trans('minecraft-manager::strings.profile.source.explicit'),
            self::SOURCE_INHERITED => trans('minecraft-manager::strings.profile.source.inherited', ['egg' => $this->sourceEggName ?? '—']),
            default => trans('minecraft-manager::strings.profile.source.heuristic', ['loader' => $this->loader?->getLabel() ?? '—']),
        };
    }

    public function isDetected(): bool
    {
        return $this->source === self::SOURCE_HEURISTIC;
    }
}
