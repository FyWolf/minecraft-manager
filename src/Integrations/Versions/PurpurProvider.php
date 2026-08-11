<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

use FyWolf\MinecraftManager\Integrations\ApiClient;

/**
 * PurpurMC.
 */
class PurpurProvider extends ApiClient implements VersionProvider
{
    public function key(): string
    {
        return 'purpur';
    }

    public function label(): string
    {
        return 'Purpur';
    }

    protected function baseUrl(): string
    {
        return (string) config('minecraft-manager.versions.purpur_url', 'https://api.purpurmc.org/v2');
    }

    public function gameVersions(): array
    {
        $payload = $this->remember(
            'mcm:purpur:versions',
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson('/purpur'),
        );

        return array_reverse(array_values((array) ($payload['versions'] ?? [])));
    }

    public function builds(string $gameVersion): array
    {
        $payload = $this->remember(
            "mcm:purpur:builds:$gameVersion",
            (int) config('minecraft-manager.cache.versions', 1800),
            fn () => $this->getJson("/purpur/$gameVersion"),
        );

        // Purpur nests the list under builds.all and names the newest in
        // builds.latest.
        $all = (array) ($payload['builds']['all'] ?? []);
        $latest = (string) ($payload['builds']['latest'] ?? '');

        $builds = [];

        foreach (array_reverse(array_values($all)) as $build) {
            $id = (string) $build;

            $builds[] = [
                'id' => $id,
                'label' => '#' . $id . ($id === $latest ? ' (latest)' : ''),
            ];
        }

        return $builds;
    }

    public function downloadUrl(string $gameVersion, string $buildId): ?string
    {
        // Purpur serves the jar directly; no metadata lookup is needed.
        return rtrim($this->baseUrl(), '/') . "/purpur/$gameVersion/$buildId/download";
    }
}
