<?php

namespace Database\Seeders;

use App\Models\Egg;
use Exception;
use FyWolf\MinecraftManager\Models\CapabilityProfile;
use FyWolf\MinecraftManager\Models\EggCapabilityProfile;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Creates the built-in profiles and maps the stock Minecraft eggs to them.
 *
 * The class name is load-bearing: `Plugin::getSeeder()` builds it as
 * `\Database\Seeders\{Str::studly($plugin->name)}Seeder` from the plugin's
 * *display name*, and returns null — with no error, no log line and no hint in
 * the UI — if the class does not exist. "Minecraft Manager" is therefore
 * `MinecraftManagerSeeder`, and renaming the plugin in plugin.json without
 * renaming this class silently disables all of it.
 *
 * Runs on install AND on every update (updatePlugin -> installPlugin ->
 * runPluginSeeder), so everything here has to be idempotent.
 */
class MinecraftManagerSeeder extends Seeder
{
    /**
     * Stock egg UUIDs, taken from the panel's own
     * 2024_06_02_205622_update_stock_egg_uuid.php migration. These are exact and
     * cheap, but incomplete — notably there is no pinned Fabric UUID — so the
     * heuristic pass afterwards is not optional.
     */
    private const STOCK_EGGS = [
        '9ac39f3d-0c34-4d93-8174-c52ab9e6c57b' => 'vanilla',
        '5da37ef6-58da-4169-90a6-e683e1721247' => 'paper',
        'ed072427-f209-4603-875c-f540c6dd5a65' => 'forge',
        '9e6b409e-4028-4947-aea8-50a2c404c271' => 'bungeecord',
        'f0d2f88f-1ff3-42a0-b03f-ac44c5571e6d' => 'vanilla', // Sponge (SpongeVanilla)
    ];

    /**
     * Signature must match Illuminate\Database\Seeder::__invoke(array $parameters = []),
     * which the panel calls via `$seeder->__invoke()` during plugin install.
     *
     * @param array<mixed> $parameters
     */
    public function __invoke(array $parameters = []): void
    {
        $profiles = $this->seedProfiles();

        $this->mapStockEggs($profiles);

        $this->mapRemainingEggs($profiles);
    }

    /**
     * @return array<string, CapabilityProfile> keyed by loader
     */
    private function seedProfiles(): array
    {
        $profiles = [];

        foreach ((array) config('minecraft-manager.profiles', []) as $loader => $definition) {
            if (! is_array($definition) || blank($definition['name'] ?? null)) {
                continue;
            }

            try {
                // Keyed on name so an administrator who renamed a profile keeps
                // their edits rather than getting a duplicate on every update.
                $profiles[$loader] = CapabilityProfile::firstOrCreate(
                    ['name' => $definition['name']],
                    [
                        'loader' => $loader,
                        'capabilities' => $definition['capabilities'] ?? [],
                        'content_dir' => $definition['content_dir'] ?? null,
                        'worlds_dir' => $definition['worlds_dir'] ?? '/',
                        'dimension_layout' => $definition['dimension_layout'] ?? 'vanilla',
                        'version_provider' => $definition['version_provider'] ?? null,
                        'jar_path' => $definition['jar_path'] ?? null,
                        'mc_version_variables' => $definition['mc_version_variables'] ?? [],
                        'loader_version_variables' => $definition['loader_version_variables'] ?? [],
                        'config_files' => $definition['config_files'] ?? [],
                    ],
                );
            } catch (Exception $exception) {
                Log::warning('minecraft-manager: could not seed profile', [
                    'loader' => $loader,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $profiles;
    }

    /**
     * @param array<string, CapabilityProfile> $profiles
     */
    private function mapStockEggs(array $profiles): void
    {
        foreach (self::STOCK_EGGS as $uuid => $loader) {
            if (! isset($profiles[$loader])) {
                continue;
            }

            $egg = Egg::where('uuid', $uuid)->first();

            if ($egg) {
                $this->map($egg->id, $profiles[$loader]->id);
            }
        }
    }

    /**
     * Guess a profile for every Minecraft egg still unmapped.
     *
     * Most panels will map almost nothing at install time, because eggs are
     * imported from the pelican-eggs organisation *after* the panel is set up —
     * which is what `minecraft-manager:sync-profiles` is for.
     *
     * @param array<string, CapabilityProfile> $profiles
     */
    private function mapRemainingEggs(array $profiles): void
    {
        $resolver = new CapabilityResolver();

        Egg::query()->chunkById(100, function ($eggs) use ($profiles, $resolver) {
            foreach ($eggs as $egg) {
                $loader = $resolver->detectLoaderForEgg($egg);

                if (! $loader || ! isset($profiles[$loader->value])) {
                    continue;
                }

                $this->map($egg->id, $profiles[$loader->value]->id);
            }
        });
    }

    /**
     * Keyed on egg_id alone, so a plugin update never re-points a mapping an
     * administrator chose deliberately.
     */
    private function map(int $eggId, int $profileId): void
    {
        try {
            EggCapabilityProfile::firstOrCreate(
                ['egg_id' => $eggId],
                ['mc_capability_profile_id' => $profileId],
            );
        } catch (Exception $exception) {
            // Unique-constraint races under concurrent installs, same as the
            // player-counter seeder guards against.
            Log::debug('minecraft-manager: egg mapping skipped', [
                'egg_id' => $eggId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
