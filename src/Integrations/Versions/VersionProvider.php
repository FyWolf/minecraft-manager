<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

/**
 * A source of runnable server jars.
 *
 * Deliberately narrow. Forge and NeoForge have no implementation here and never
 * will: what they publish is an *installer* that has to be executed to produce
 * a server, so pulling their artifact over server.jar yields a server that
 * cannot boot. Those loaders change version by writing the startup variable and
 * letting the egg's own install script do the work — which is what a null
 * `version_provider` on the capability profile means.
 */
interface VersionProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * Minecraft versions this provider can supply, newest first.
     *
     * @return array<int, string>
     */
    public function gameVersions(): array;

    /**
     * Builds available for one Minecraft version, newest first.
     *
     * Returns a list of ['id' => string, 'label' => string]. Vanilla has no
     * concept of a build, so it returns a single synthetic entry.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function builds(string $gameVersion): array;

    /**
     * The download URL for a build, or null if it cannot be resolved.
     */
    public function downloadUrl(string $gameVersion, string $buildId): ?string;
}
