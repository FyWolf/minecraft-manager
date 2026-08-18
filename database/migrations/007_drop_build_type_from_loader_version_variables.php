<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take `BUILD_TYPE` out of Forge's loader version variables.
 *
 * `loader_version_variables` is *alternatives for one role* — which variable
 * holds the loader version. `BUILD_TYPE` is not a spelling of that: it holds
 * `recommended` or `latest`, a promotion channel. Listing it there caused two
 * separate wrongs, and the second is the one people saw:
 *
 *  - the chosen Forge version was written into `BUILD_TYPE` as well as into
 *    `FORGE_VERSION`, so a field meaning "give me the recommended build" ended
 *    up holding `65.1.2`;
 *  - the code that worked out whether this egg wants `26.2-65.1.2` or a bare
 *    `65.1.2` read whichever of the two came back first, so on an egg listing
 *    `BUILD_TYPE` first it read `recommended`, saw no dot-dash artifact, and
 *    concluded the egg wanted bare builds — writing `65.1.2` for a chosen
 *    `26.2-65.1.2`.
 *
 * The format is no longer inferred at all (the egg's own validation rules
 * decide), but the list still has to be correct: a version written into
 * `BUILD_TYPE` is wrong however it is spelled.
 *
 * The seeder cannot do this. It is `firstOrCreate` keyed on name, deliberately,
 * so an update never overwrites an administrator's edits — which means a fix to
 * the built-in definition never reaches an installation that already has the row.
 *
 * Narrow on purpose: only `BUILD_TYPE`, only where it sits beside a real loader
 * version variable, so a profile an administrator has deliberately pointed at
 * something else is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('mc_capability_profiles')->get() as $profile) {
            $variables = json_decode((string) $profile->loader_version_variables, true);

            if (! is_array($variables) || ! in_array('BUILD_TYPE', $variables, true)) {
                continue;
            }

            $remaining = array_values(array_filter(
                $variables,
                fn ($name) => strtoupper((string) $name) !== 'BUILD_TYPE',
            ));

            // Only ever a removal, never an emptying. A profile whose *only*
            // loader variable is BUILD_TYPE is one somebody configured by hand
            // to mean something this migration cannot know, and clearing it
            // would take away version changing entirely.
            if ($remaining === []) {
                continue;
            }

            DB::table('mc_capability_profiles')
                ->where('id', $profile->id)
                ->update(['loader_version_variables' => json_encode($remaining)]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Putting BUILD_TYPE back would restore a
        // configuration that writes a version string into a field holding
        // `recommended`/`latest` — there is no state worth rolling back to.
    }
};
