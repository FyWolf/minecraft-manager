<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant the new `addons` capability to profiles that already exist.
 *
 * The seeder cannot do this. It uses firstOrCreate keyed on name, deliberately,
 * so that a plugin update never overwrites an administrator's edits — which
 * means a capability added after installation would never reach an existing
 * profile, and the Addons page would 403 on every server that upgraded rather
 * than installed fresh.
 *
 * Additive and idempotent: profiles that already list it, and any an admin
 * deliberately edited to exclude it after this ran, are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('mc_capability_profiles')->get() as $profile) {
            $capabilities = json_decode((string) $profile->capabilities, true);

            if (! is_array($capabilities) || in_array('addons', $capabilities, true)) {
                continue;
            }

            $capabilities[] = 'addons';

            DB::table('mc_capability_profiles')
                ->where('id', $profile->id)
                ->update(['capabilities' => json_encode(array_values($capabilities))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('mc_capability_profiles')->get() as $profile) {
            $capabilities = json_decode((string) $profile->capabilities, true);

            if (! is_array($capabilities)) {
                continue;
            }

            $remaining = array_values(array_filter($capabilities, fn ($c) => $c !== 'addons'));

            if ($remaining !== $capabilities) {
                DB::table('mc_capability_profiles')
                    ->where('id', $profile->id)
                    ->update(['capabilities' => json_encode($remaining)]);
            }
        }
    }
};
