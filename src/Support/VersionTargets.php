<?php

namespace FyWolf\MinecraftManager\Support;

/**
 * Which startup variable takes which version.
 *
 * A capability profile lists *alternatives* per role — `['MINECRAFT_VERSION',
 * 'MC_VERSION']` means "whichever of these this egg has", not "write both". The
 * write path keyed its targets by variable name instead, so every listed name
 * the egg exposed was written, and the comment claiming otherwise was simply
 * false.
 *
 * That was harmless while the alternatives were synonyms. It stopped being
 * harmless for Forge, whose list was `['FORGE_VERSION', 'BUILD_TYPE']` — two
 * different things, not two spellings of one. `BUILD_TYPE` holds
 * `recommended`/`latest`, so writing a version into it is nonsense, and reading
 * a format hint out of it (`recommended` has no dash, so "this egg must want
 * bare builds") is how `26.2-65.1.2` came to be written as `65.1.2`.
 *
 * Pure, so `tests/VersionTargetsTest.php` can pin it without the framework —
 * this mapping is exactly what broke, twice.
 */
final class VersionTargets
{
    public const ROLE_MC = 'mc';

    public const ROLE_LOADER = 'loader';

    /**
     * Pick one variable per role, honouring the profile's declared order.
     *
     * The profile's order is authoritative rather than the egg's, because the
     * egg's order is however its variables happen to be stored — which is what
     * made the answer depend on whether `BUILD_TYPE` came back before
     * `FORGE_VERSION`.
     *
     * @param  array<int, string>  $mcNames      profile's mcVersionVariables, best first
     * @param  array<int, string>  $loaderNames  profile's loaderVersionVariables, best first
     * @param  array<int, string>  $available    env variable names this egg actually exposes
     * @return array<string, string>             env variable name => role
     */
    public static function plan(array $mcNames, array $loaderNames, array $available): array
    {
        $upper = [];

        foreach ($available as $name) {
            $upper[strtoupper((string) $name)] = (string) $name;
        }

        $plan = [];

        foreach ([self::ROLE_MC => $mcNames, self::ROLE_LOADER => $loaderNames] as $role => $names) {
            foreach ($names as $name) {
                $key = strtoupper((string) $name);

                // A variable already claimed by an earlier role is not claimed
                // again: one variable cannot hold both the Minecraft version and
                // the loader version, and silently overwriting the first with
                // the second is the worst of the three possible outcomes.
                if (! isset($upper[$key]) || isset($plan[$upper[$key]])) {
                    continue;
                }

                $plan[$upper[$key]] = $role;

                break;
            }
        }

        return $plan;
    }
}
