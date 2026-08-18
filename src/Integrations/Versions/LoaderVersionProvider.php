<?php

namespace FyWolf\MinecraftManager\Integrations\Versions;

/**
 * A source of *loader* versions, for software that ships an installer.
 *
 * Deliberately separate from {@see VersionProvider}, which is a source of
 * runnable server jars. Forge cannot implement that interface — pulling its
 * artifact over server.jar yields a server that cannot boot — but it very much
 * has a version list, and until this existed the plugin had nowhere to put one.
 *
 * That gap is what the bug was: the reinstall path offered *Minecraft* versions
 * for a Forge server and never wrote FORGE_VERSION at all, so changing version
 * left the old Forge build behind for the install script to fail on.
 *
 * A profile keeps `version_provider => null` for these loaders. This is looked
 * up by loader instead, so nothing about the jar-swap path changes.
 */
interface LoaderVersionProvider
{
    /** Matches `ModLoader::value`. */
    public function key(): string;

    public function label(): string;

    /**
     * Minecraft versions this loader has builds for, newest first.
     *
     * @return array<int, string>
     */
    public function gameVersions(): array;

    /**
     * Loader builds for one Minecraft version, newest first.
     *
     * `value` is what gets written to the startup variable and `label` is what
     * the dropdown shows. They differ because some eggs want the full artifact
     * (`1.15.2-31.2.4`) and some want the bare build (`31.2.4`), while the label
     * always shows the artifact — that is the string somebody is matching
     * against the Forge website.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function buildsFor(string $gameVersion, bool $fullArtifact = true): array;

    /** Whether the upstream answered at all — an empty list is not the same as "no builds". */
    public function isAvailable(): bool;
}
