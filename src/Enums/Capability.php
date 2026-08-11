<?php

namespace FyWolf\MinecraftManager\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a server's egg is allowed to do.
 *
 * Worlds and Configs are deliberately separate rather than one "basics" flag: a
 * Velocity proxy has neither worlds nor server.properties, but does have
 * velocity.toml and a plugins directory. Collapsing them would force a proxy
 * into a Worlds page that can only ever be empty.
 */
enum Capability: string implements HasLabel
{
    case Worlds = 'worlds';
    case Configs = 'configs';
    case Versions = 'versions';
    case Mods = 'mods';
    case Plugins = 'plugins';
    case Modpacks = 'modpacks';
    case ResourcePacks = 'resourcepacks';
    case Datapacks = 'datapacks';
    case Addons = 'addons';

    public function getLabel(): string
    {
        return match ($this) {
            self::Worlds => 'Worlds',
            self::Configs => 'Configuration files',
            self::Versions => 'Version switching',
            self::Mods => 'Mods',
            self::Plugins => 'Plugins',
            self::Modpacks => 'Modpacks',
            self::ResourcePacks => 'Resource packs',
            self::Datapacks => 'Datapacks',
            self::Addons => 'Addons',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Worlds => 'Browse, archive, switch and delete world folders.',
            self::Configs => 'Edit server.properties and friends as a form.',
            self::Versions => 'Change the Minecraft or loader version.',
            self::Mods => 'Browse and install mods into the mods directory.',
            self::Plugins => 'Browse and install plugins into the plugins directory.',
            self::Modpacks => 'Install a whole modpack, resolving every file.',
            self::ResourcePacks => 'Browse and install resource packs.',
            self::Datapacks => 'Browse and install datapacks into a world.',
            self::Addons => 'One-click extras such as BlueMap, some of which claim an extra port.',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
