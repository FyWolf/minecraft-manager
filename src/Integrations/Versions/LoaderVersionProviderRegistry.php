<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

use FyWolf\MinecraftManager\Enums\ModLoader;

/**
 * Loader-version sources, keyed by `ModLoader::value`.
 *
 * Separate from {@see VersionProviderRegistry}, which is keyed by the capability
 * profile's `version_provider` column. This one is keyed by the *loader*,
 * because the profiles that need it deliberately have no `version_provider` —
 * that null is what routes them to reinstall in the first place.
 */
class LoaderVersionProviderRegistry
{
    /** @var array<string, LoaderVersionProvider> */
    private array $providers = [];

    public function register(LoaderVersionProvider $provider): self
    {
        $this->providers[$provider->key()] = $provider;

        return $this;
    }

    public function for(?ModLoader $loader): ?LoaderVersionProvider
    {
        return $loader ? ($this->providers[$loader->value] ?? null) : null;
    }

    /** @return array<string, LoaderVersionProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
