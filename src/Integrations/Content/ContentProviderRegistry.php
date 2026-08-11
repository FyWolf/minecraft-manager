<?php

namespace FyWolf\MinecraftManager\Integrations\Content;

use FyWolf\MinecraftManager\Enums\ContentType;

/**
 * The set of providers that are usable right now.
 *
 * Registered as a container singleton from the service provider, the same shape
 * player-counter uses for its query-type service.
 */
class ContentProviderRegistry
{
    /** @var array<string, ContentProvider> */
    private array $providers = [];

    public function register(ContentProvider $provider): self
    {
        $this->providers[$provider->key()] = $provider;

        return $this;
    }

    /**
     * Every registered provider, whether usable or not.
     *
     * @return array<string, ContentProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Providers that are configured and support the given type.
     *
     * @return array<string, ContentProvider>
     */
    public function available(?ContentType $type = null): array
    {
        return array_filter(
            $this->providers,
            fn (ContentProvider $provider) => $provider->isAvailable()
                && ($type === null || $provider->supports($type)),
        );
    }

    public function get(string $key): ?ContentProvider
    {
        $provider = $this->providers[$key] ?? null;

        return $provider?->isAvailable() ? $provider : null;
    }

    /**
     * The provider to use when the caller expressed no preference.
     */
    public function default(?ContentType $type = null): ?ContentProvider
    {
        $available = $this->available($type);

        return $available === [] ? null : reset($available);
    }

    public function hasMoreThanOne(?ContentType $type = null): bool
    {
        return count($this->available($type)) > 1;
    }
}
