<?php

namespace FyWolf\MinecraftManager\Support;

use App\Models\Egg;
use App\Models\Server;
use FyWolf\MinecraftManager\Enums\ModLoader;
use FyWolf\MinecraftManager\Models\CapabilityProfile;
use Illuminate\Support\Str;

/**
 * Decides what a given server is allowed to do.
 *
 * Every page in this plugin asks this one question, and a null answer means the
 * page does not exist for that server — no navigation entry, no empty state, no
 * error. An egg nobody has taught us about is invisible rather than broken.
 *
 * Resolution order:
 *
 *   1. explicit   — a profile an admin mapped to this egg
 *   2. inherited  — the profile mapped to the egg's parent (config_from)
 *   3. heuristic  — guessed from tags, features and startup variables
 *   4. null
 *
 * Step 3 is why this class exists at all. Gating on egg *features* named `mods`
 * or `plugins` (as minecraft-modrinth does) cannot work: the panel registers
 * exactly five feature ids — eula, java_version, gsl_token, pid_limit,
 * steam_disk_space — so those values are never present unless an administrator
 * hand-edits the egg. `eula` and `java_version` ARE real, and together with the
 * `minecraft` tag they are a reliable "this is Minecraft Java" signal.
 */
class CapabilityResolver
{
    /** @var array<int, ?ResolvedProfile> */
    private array $memo = [];

    public function for(Server $server): ?ResolvedProfile
    {
        $eggId = $server->egg_id;

        if (array_key_exists($eggId, $this->memo)) {
            return $this->memo[$eggId];
        }

        return $this->memo[$eggId] = $this->resolve($server);
    }

    private function resolve(Server $server): ?ResolvedProfile
    {
        $server->loadMissing('egg');

        $egg = $server->egg;

        if (! $egg) {
            return null;
        }

        // 1. Explicit mapping. The admin always wins.
        if ($profile = $this->profileFor($egg)) {
            return ResolvedProfile::fromModel($profile, ResolvedProfile::SOURCE_EXPLICIT);
        }

        // 2. The parent egg's mapping. Covers the very common case of a
        //    customised copy of a stock egg, mirroring how the panel itself
        //    falls back for features, config files and the file denylist.
        if ($egg->config_from) {
            $parent = $egg->configFrom;

            if ($parent && ($profile = $this->profileFor($parent))) {
                return ResolvedProfile::fromModel($profile, ResolvedProfile::SOURCE_INHERITED, $parent->name);
            }
        }

        // 3. Guess.
        if (! config('minecraft-manager.heuristics.enabled', true)) {
            return null;
        }

        if (! $this->looksLikeMinecraft($egg)) {
            return null;
        }

        $loader = $this->detectLoader($egg, $server);

        if (! $loader) {
            return null;
        }

        return ResolvedProfile::fromDefaults($loader);
    }

    /**
     * Deliberately queried through this plugin's own belongsToMany rather than
     * through the `mcCapabilityProfile` relation the service provider grafts
     * onto the core Egg model. The graft exists for other code's convenience
     * and for eager loading; resolution itself must not depend on it having
     * booted, because this runs inside every page's canAccess().
     */
    private function profileFor(Egg $egg): ?CapabilityProfile
    {
        return CapabilityProfile::query()
            ->whereHas('eggs', fn ($query) => $query->whereKey($egg->id))
            ->first();
    }

    /**
     * Is this a Minecraft Java egg at all?
     *
     * The `minecraft` tag OR one of the panel's genuinely registered feature
     * ids. OR rather than AND deliberately: plenty of eggs carry `eula` and
     * `java_version` without ever being tagged.
     */
    private function looksLikeMinecraft(Egg $egg): bool
    {
        $tags = array_map('strtolower', $egg->tags ?? []);

        if (in_array('minecraft', $tags, true)) {
            return true;
        }

        // inherit_features, not features — a child egg leaves its features null
        // and inherits the parent's.
        $features = $egg->inherit_features ?? $egg->features ?? [];

        $markers = (array) config('minecraft-manager.heuristics.features', ['eula', 'java_version']);

        return count(array_intersect($features, $markers)) > 0;
    }

    /**
     * Detect the loader from tags and the egg name.
     *
     * The config's token order is load-bearing and must stay most-specific
     * first: `neoforge` before `forge`, because "neoforge" contains "forge";
     * `purpur` and `folia` before `paper`, because those forks carry the paper
     * tag as well as their own.
     */
    private function detectLoader(Egg $egg, Server $server): ?ModLoader
    {
        $haystack = array_map('strtolower', $egg->tags ?? []);

        // Egg names are the other reliable signal — "Forge Minecraft",
        // "Paper", "Fabric" are the stock names.
        foreach (preg_split('/[^a-z0-9]+/i', Str::lower($egg->name)) ?: [] as $token) {
            if ($token !== '') {
                $haystack[] = $token;
            }
        }

        $haystack = array_unique($haystack);

        foreach ((array) config('minecraft-manager.heuristics.loader_tokens', []) as $loaderValue => $tokens) {
            foreach ((array) $tokens as $token) {
                if (in_array($token, $haystack, true)) {
                    return ModLoader::tryFrom($loaderValue);
                }
            }
        }

        // Nothing matched by name or tag. Fall back to the startup variables,
        // which are the strongest remaining signal — a FABRIC_VERSION variable
        // means Fabric no matter what the egg is called.
        return $this->detectLoaderFromVariables($server);
    }

    private function detectLoaderFromVariables(Server $server): ?ModLoader
    {
        $names = $server->variables
            ->pluck('env_variable')
            ->map(fn ($name) => strtoupper((string) $name))
            ->all();

        $signatures = [
            'FABRIC_VERSION' => ModLoader::Fabric,
            'QUILT_VERSION' => ModLoader::Quilt,
            'NEOFORGE_VERSION' => ModLoader::NeoForge,
            'FORGE_VERSION' => ModLoader::Forge,
            // BUILD_NUMBER alongside a Minecraft version is the Paper egg's
            // signature; checked last because it is the least distinctive.
            'BUILD_NUMBER' => ModLoader::Paper,
        ];

        foreach ($signatures as $variable => $loader) {
            if (in_array($variable, $names, true)) {
                return $loader;
            }
        }

        // A Minecraft egg with no loader signal at all is Vanilla — which
        // correctly yields worlds, configs and versions but no mod browser.
        return ModLoader::Vanilla;
    }

    /**
     * Best-effort loader detection for an egg with no server attached, used by
     * the admin "unmapped eggs" suggestion and the seeder.
     */
    public function detectLoaderForEgg(Egg $egg): ?ModLoader
    {
        if (! $this->looksLikeMinecraft($egg)) {
            return null;
        }

        $haystack = array_map('strtolower', $egg->tags ?? []);

        foreach (preg_split('/[^a-z0-9]+/i', Str::lower($egg->name)) ?: [] as $token) {
            if ($token !== '') {
                $haystack[] = $token;
            }
        }

        $haystack = array_unique($haystack);

        foreach ((array) config('minecraft-manager.heuristics.loader_tokens', []) as $loaderValue => $tokens) {
            foreach ((array) $tokens as $token) {
                if (in_array($token, $haystack, true)) {
                    return ModLoader::tryFrom($loaderValue);
                }
            }
        }

        // Without a server we cannot probe startup variables, but we can look at
        // the egg's own variable definitions.
        $names = $egg->variables()->pluck('env_variable')->map(fn ($n) => strtoupper((string) $n))->all();

        foreach ([
            'FABRIC_VERSION' => ModLoader::Fabric,
            'QUILT_VERSION' => ModLoader::Quilt,
            'NEOFORGE_VERSION' => ModLoader::NeoForge,
            'FORGE_VERSION' => ModLoader::Forge,
            'BUILD_NUMBER' => ModLoader::Paper,
        ] as $variable => $loader) {
            if (in_array($variable, $names, true)) {
                return $loader;
            }
        }

        return ModLoader::Vanilla;
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
