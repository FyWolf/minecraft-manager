<?php

namespace FyWolf\MinecraftManager\Support;

/**
 * Reading Forge's published version list.
 *
 * Deliberately free of the framework and of HTTP so the parsing can be tested
 * on a fixture — `tests/ForgeVersionsTest.php` requires this file directly, the
 * way `PropertiesFileTest` does. Everything that talks to the network lives in
 * {@see \FyWolf\MinecraftManager\Integrations\Versions\ForgeProvider}.
 *
 * A Forge artifact version is `{minecraft}-{build}`: `1.15.2-31.2.4`. That whole
 * string is what the egg's `FORGE_VERSION` normally wants, and it is what the
 * user recognises — a bare `31.2.4` says nothing about which Minecraft it is
 * for, and `26.2` (a Minecraft version under the new calendar scheme) is not a
 * Forge version at all.
 */
final class ForgeVersions
{
    /**
     * Group `maven-metadata.xml` into `minecraft => [build, …]`, newest first.
     *
     * Three things about this document that a reasonable implementation gets
     * wrong:
     *
     *  - **It is not ordered.** `1.15.2` is listed descending and `26.2`
     *    ascending, in the same file. Taking it as published puts the oldest
     *    build at the top of the dropdown for some Minecraft versions and the
     *    newest for others, which looks like a filtering bug and is impossible
     *    to spot without checking two versions.
     *  - **378 of 5041 entries carry a second dash** — `1.12.2-14.23.4.2720-4627`,
     *    `1.11-13.19.0.2129-1.11.x`. Splitting on the *first* dash is what keeps
     *    those intact; splitting on the last, or on all, mangles them.
     *  - **The Minecraft half is not always `1.x`.** Mojang's calendar scheme
     *    produces `26.2-65.1.2`, so anything matching `/^1\./` to find the game
     *    version silently drops every current release.
     *
     * @return array<string, array<int, string>>
     */
    public static function parseMavenMetadata(string $xml): array
    {
        preg_match_all('#<version>([^<]+)</version>#', $xml, $matches);

        $grouped = [];

        foreach ($matches[1] as $artifact) {
            [$game, $build] = self::split(trim($artifact));

            if ($game === null || $build === null) {
                continue;
            }

            $grouped[$game][] = $build;
        }

        foreach ($grouped as $game => $builds) {
            $unique = array_values(array_unique($builds));

            usort($unique, static fn (string $a, string $b): int => version_compare($b, $a));

            $grouped[$game] = $unique;
        }

        uksort($grouped, static fn (string $a, string $b): int => self::compareGameVersions($b, $a));

        return $grouped;
    }

    /**
     * Split `1.15.2-31.2.4` into `['1.15.2', '31.2.4']`.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function split(string $artifact): array
    {
        $dash = strpos($artifact, '-');

        if ($dash === false || $dash === 0 || $dash === strlen($artifact) - 1) {
            return [null, null];
        }

        return [substr($artifact, 0, $dash), substr($artifact, $dash + 1)];
    }

    public static function artifact(string $gameVersion, string $build): string
    {
        return $gameVersion . '-' . $build;
    }

    /**
     * Order two Minecraft versions, newest last (this is a `<=>`-style compare).
     *
     * `26.2` is newer than `1.21.8` even though 26 > 1 makes that accidentally
     * work under `version_compare`; it is written out rather than relied on,
     * because the accident stops being one the moment Mojang ships `2.x` of
     * anything. A version with no leading `1.` belongs to the calendar scheme
     * and is newer than every `1.x` release, full stop.
     */
    public static function compareGameVersions(string $a, string $b): int
    {
        $aLegacy = str_starts_with($a, '1.');
        $bLegacy = str_starts_with($b, '1.');

        if ($aLegacy !== $bLegacy) {
            return $aLegacy ? -1 : 1;
        }

        return version_compare($a, $b);
    }

    /**
     * The recommended and latest build per Minecraft version, from
     * `promotions_slim.json`.
     *
     * Keys there are `{minecraft}-recommended` / `{minecraft}-latest` and the
     * values are the *bare* build — so this is the one place where a bare build
     * is the correct reading of the data, and it is turned back into an
     * artifact string immediately.
     *
     * @param  array<mixed>  $payload
     * @return array<string, array{recommended: ?string, latest: ?string}>
     */
    public static function parsePromotions(array $payload): array
    {
        $promos = $payload['promos'] ?? null;

        if (! is_array($promos)) {
            return [];
        }

        $result = [];

        foreach ($promos as $key => $build) {
            if (! is_string($key) || ! is_scalar($build)) {
                continue;
            }

            $dash = strrpos($key, '-');

            if ($dash === false) {
                continue;
            }

            $game = substr($key, 0, $dash);
            $kind = substr($key, $dash + 1);

            if (! in_array($kind, ['recommended', 'latest'], true) || $game === '') {
                continue;
            }

            $result[$game] ??= ['recommended' => null, 'latest' => null];
            $result[$game][$kind] = (string) $build;
        }

        return $result;
    }

    /**
     * Label a build for the dropdown.
     *
     * The artifact string leads because it is the value the variable actually
     * takes — somebody checking their egg against the Forge website is matching
     * that string, not a decoration of it.
     *
     * @param  array{recommended: ?string, latest: ?string}|null  $promotions
     */
    public static function label(string $gameVersion, string $build, ?array $promotions): string
    {
        $label = self::artifact($gameVersion, $build);

        $tags = [];

        if ($promotions && ($promotions['recommended'] ?? null) === $build) {
            $tags[] = 'recommended';
        }

        if ($promotions && ($promotions['latest'] ?? null) === $build) {
            $tags[] = 'latest';
        }

        return $tags === [] ? $label : $label . ' — ' . implode(', ', $tags);
    }

    /**
     * Whether this egg wants the full artifact string or the bare build.
     *
     * Forge eggs disagree, and both spellings are in the wild: some install
     * scripts take `FORGE_VERSION=1.15.2-31.2.4` and pass it straight to the
     * installer URL, others take `FORGE_VERSION=31.2.4` and build the URL from
     * the Minecraft version themselves. Writing the wrong one produces a server
     * whose install script 404s.
     *
     * So it is read off whatever the variable already holds rather than assumed.
     * A current value carrying a dash is the full form; anything else is bare.
     * An empty variable falls back to the full form, which is what the artifact
     * *is* and what the Forge website shows.
     *
     * This can only be wrong when the variable is empty AND the egg wants the
     * bare build — and the egg's own rules are validated before the write, with
     * the rejection now reported rather than swallowed, so that case is loud.
     */
    public static function wantsFullArtifact(?string $currentValue): bool
    {
        $current = trim((string) $currentValue);

        if ($current === '') {
            return true;
        }

        return self::split($current)[0] !== null;
    }
}
