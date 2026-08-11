<?php

namespace FyWolf\MinecraftManager\Console\Commands;

use App\Models\Egg;
use FyWolf\MinecraftManager\Models\CapabilityProfile;
use FyWolf\MinecraftManager\Models\EggCapabilityProfile;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use Illuminate\Console\Command;

/**
 * Map Minecraft eggs to capability profiles.
 *
 * Needed because eggs are imported from the pelican-eggs organisation *after*
 * the panel is set up, so the install-time seeder usually has almost nothing to
 * work with. Run this after importing eggs.
 *
 * Auto-registered: the panel resolves every class under src/Console/Commands
 * without them being declared anywhere.
 */
class SyncProfilesCommand extends Command
{
    protected $signature = 'minecraft-manager:sync-profiles
                            {--force : Re-point eggs that are already mapped}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Map Minecraft eggs to Minecraft Manager capability profiles';

    public function handle(CapabilityResolver $resolver): int
    {
        $profiles = CapabilityProfile::whereNotNull('loader')->get()->keyBy('loader');

        if ($profiles->isEmpty()) {
            $this->error('No capability profiles exist. Reinstall the plugin to seed them.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $mapped = 0;
        $repointed = 0;
        $skipped = 0;
        $unmatched = [];
        $rows = [];

        foreach (Egg::all() as $egg) {
            $existing = EggCapabilityProfile::where('egg_id', $egg->id)->first();

            if ($existing && ! $force) {
                $skipped++;

                continue;
            }

            $loader = $resolver->detectLoaderForEgg($egg);

            if (! $loader || ! $profiles->has($loader->value)) {
                // Not a Minecraft egg, or a loader with no profile. Either way,
                // leaving it unmapped is the correct outcome — the plugin simply
                // will not appear on servers using it.
                if ($loader) {
                    $unmatched[] = $egg->name;
                }

                continue;
            }

            $profile = $profiles->get($loader->value);

            $rows[] = [
                $egg->name,
                $profile->name,
                $existing ? 're-point' : 'new',
            ];

            if ($dryRun) {
                continue;
            }

            if ($existing) {
                $existing->update(['mc_capability_profile_id' => $profile->id]);
                $repointed++;
            } else {
                EggCapabilityProfile::create([
                    'egg_id' => $egg->id,
                    'mc_capability_profile_id' => $profile->id,
                ]);
                $mapped++;
            }
        }

        if ($rows !== []) {
            $this->table(['Egg', 'Profile', 'Action'], $rows);
        }

        if ($dryRun) {
            $this->comment(count($rows) . ' egg(s) would change. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $this->info("Mapped $mapped new egg(s), re-pointed $repointed.");

        if ($skipped > 0) {
            $this->comment("Left $skipped already-mapped egg(s) alone. Use --force to re-point them.");
        }

        if ($unmatched !== []) {
            $this->comment('No profile for the detected loader of: ' . implode(', ', $unmatched));
        }

        return self::SUCCESS;
    }
}
