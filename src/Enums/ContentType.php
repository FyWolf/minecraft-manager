<?php

namespace FyWolf\MinecraftManager\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A kind of installable content, mapped onto each provider's own vocabulary.
 */
enum ContentType: string implements HasLabel
{
    case Mod = 'mod';
    case Plugin = 'plugin';
    case Modpack = 'modpack';
    case ResourcePack = 'resourcepack';
    case Datapack = 'datapack';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mod => 'Mods',
            self::Plugin => 'Plugins',
            self::Modpack => 'Modpacks',
            self::ResourcePack => 'Resource packs',
            self::Datapack => 'Datapacks',
        };
    }

    public function capability(): Capability
    {
        return match ($this) {
            self::Mod => Capability::Mods,
            self::Plugin => Capability::Plugins,
            self::Modpack => Capability::Modpacks,
            self::ResourcePack => Capability::ResourcePacks,
            self::Datapack => Capability::Datapacks,
        };
    }

    /**
     * Modrinth `project_type` facet value.
     */
    public function modrinthProjectType(): string
    {
        return match ($this) {
            self::Mod => 'mod',
            self::Plugin => 'plugin',
            self::Modpack => 'modpack',
            self::ResourcePack => 'resourcepack',
            self::Datapack => 'datapack',
        };
    }

    /**
     * CurseForge `classId`, read from config so it can be corrected without a
     * release — CurseForge does not publish these as stable constants and the
     * provider prefers ids discovered from `/v1/categories` at runtime.
     */
    public function curseForgeClassId(): ?int
    {
        $key = match ($this) {
            self::Mod => 'mod',
            self::Plugin => 'plugin',
            self::Modpack => 'modpack',
            self::ResourcePack => 'resourcepack',
            self::Datapack => null,
        };

        if ($key === null) {
            return null;
        }

        return config("minecraft-manager.curseforge.class_ids.$key");
    }

    /**
     * Where this content type lands, relative to the server root.
     *
     * Mods and plugins come from the profile because they differ per loader;
     * the rest are fixed by Minecraft itself.
     */
    public function directory(?string $contentDir): ?string
    {
        return match ($this) {
            self::Mod, self::Plugin => $contentDir,
            self::ResourcePack => 'resourcepacks',
            self::Modpack => '/',
            // Datapacks live inside the active world, so the caller has to
            // resolve the world name first; there is no static answer.
            self::Datapack => null,
        };
    }
}
