<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

class VersionProviderRegistry
{
    /** @var array<string, VersionProvider> */
    private array $providers = [];

    public function register(VersionProvider $provider): self
    {
        $this->providers[$provider->key()] = $provider;

        return $this;
    }

    public function get(?string $key): ?VersionProvider
    {
        return $key ? ($this->providers[$key] ?? null) : null;
    }

    /**
     * @return array<string, VersionProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
