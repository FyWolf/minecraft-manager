<?php

namespace FyWolf\MinecraftManager\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The server software family.
 *
 * Drives three separate things that must not drift apart: which facet the
 * content providers search on, which directory content installs into, and
 * whether a version change can swap a jar or has to reinstall.
 */
enum ModLoader: string implements HasLabel
{
    case Vanilla = 'vanilla';
    case Paper = 'paper';
    case Purpur = 'purpur';
    case Folia = 'folia';
    case Fabric = 'fabric';
    case Quilt = 'quilt';
    case Forge = 'forge';
    case NeoForge = 'neoforge';
    case Velocity = 'velocity';
    case BungeeCord = 'bungeecord';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vanilla => 'Vanilla',
            self::Paper => 'Paper / Bukkit',
            self::Purpur => 'Purpur',
            self::Folia => 'Folia',
            self::Fabric => 'Fabric',
            self::Quilt => 'Quilt',
            self::Forge => 'Forge',
            self::NeoForge => 'NeoForge',
            self::Velocity => 'Velocity',
            self::BungeeCord => 'BungeeCord / Waterfall',
        };
    }

    /**
     * Every Modrinth `categories:` value that should match for this loader.
     *
     * Returned as a family, not a single value, because Modrinth facets are an
     * AND of ORs: the whole family belongs in ONE inner array so it reads as
     * "paper OR spigot OR bukkit". Sending just the primary value — which
     * minecraft-modrinth does — silently misses every Spigot-only plugin, which
     * is a large share of the Bukkit ecosystem.
     *
     * @return array<int, string>
     */
    public function modrinthCategories(): array
    {
        return match ($this) {
            self::Vanilla => [],
            self::Paper => ['paper', 'spigot', 'bukkit', 'purpur', 'folia'],
            self::Purpur => ['purpur', 'paper', 'spigot', 'bukkit'],
            self::Folia => ['folia', 'paper', 'spigot', 'bukkit'],
            self::Fabric => ['fabric'],
            self::Quilt => ['quilt', 'fabric'],
            self::Forge => ['forge'],
            self::NeoForge => ['neoforge'],
            self::Velocity => ['velocity'],
            self::BungeeCord => ['bungeecord', 'waterfall'],
        };
    }

    /**
     * CurseForge `modLoaderType`.
     *
     * Null means "do not send the parameter at all". CurseForge has no loader
     * type for the Bukkit family — sending one with classId=5 (plugins) returns
     * zero rows rather than an error, which looks exactly like "no results".
     */
    public function curseForgeLoaderType(): ?int
    {
        return match ($this) {
            self::Forge => 1,
            self::Fabric => 4,
            self::Quilt => 5,
            self::NeoForge => 6,
            default => null,
        };
    }

    /**
     * Whether this loader's upstream distributes a runnable server jar.
     *
     * Forge and NeoForge ship an *installer* that must be executed to produce a
     * server, so pulling their artifact over server.jar yields a server that
     * cannot boot. Those take the variable+reinstall path and let the egg's own
     * install script do the work.
     */
    public function hasRunnableJar(): bool
    {
        return ! in_array($this, [self::Forge, self::NeoForge, self::Quilt], true);
    }

    public function isProxy(): bool
    {
        return in_array($this, [self::Velocity, self::BungeeCord], true);
    }

    /**
     * Bukkit-family servers keep `world_nether` and `world_the_end` as SIBLING
     * folders of `world`; everything else nests dimensions inside `world/DIM-1`.
     * Getting this wrong means archiving a world without its nether.
     */
    public function dimensionLayout(): string
    {
        return match ($this) {
            self::Paper, self::Purpur, self::Folia => 'bukkit',
            default => 'vanilla',
        };
    }
}
