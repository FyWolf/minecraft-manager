<?php

require __DIR__ . '/../src/Support/VersionTargets.php';

use FyWolf\MinecraftManager\Support\VersionTargets;

$pass = 0;
$fail = 0;

function check(string $label, $actual, $expected): void
{
    global $pass, $fail;

    if ($actual === $expected) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n";
        echo "       expected: " . var_export($expected, true) . "\n";
        echo "       actual:   " . var_export($actual, true) . "\n";
    }
}

const MC = VersionTargets::ROLE_MC;
const LOADER = VersionTargets::ROLE_LOADER;

echo "One variable per role — the list is alternatives, not a set to write all of:\n";

check(
    'picks the alternative this egg actually has',
    VersionTargets::plan(['MINECRAFT_VERSION', 'MC_VERSION'], [], ['MC_VERSION', 'SERVER_JARFILE']),
    ['MC_VERSION' => MC],
);

check(
    'writes only the first alternative when the egg has both',
    VersionTargets::plan(['MINECRAFT_VERSION', 'MC_VERSION'], [], ['MC_VERSION', 'MINECRAFT_VERSION']),
    ['MINECRAFT_VERSION' => MC],
);

check(
    'both roles are planned',
    VersionTargets::plan(['MINECRAFT_VERSION'], ['FORGE_VERSION'], ['MINECRAFT_VERSION', 'FORGE_VERSION']),
    ['MINECRAFT_VERSION' => MC, 'FORGE_VERSION' => LOADER],
);

/*
 * The regression. Forge's profile listed ['FORGE_VERSION', 'BUILD_TYPE'] — two
 * different things, not two spellings of one — and the old code keyed targets by
 * variable name, so BOTH were written the Forge version. A field meaning "give
 * me the recommended build" ended up holding 65.1.2.
 *
 * BUILD_TYPE is out of the profile now, but the planner must be the thing that
 * makes a second entry harmless, or the next profile edit reintroduces it.
 */
echo "\nThe regression — a second name must not become a second write:\n";

check(
    'only the first loader variable is claimed',
    VersionTargets::plan([], ['FORGE_VERSION', 'BUILD_TYPE'], ['BUILD_TYPE', 'FORGE_VERSION']),
    ['FORGE_VERSION' => LOADER],
);

check(
    'and the egg\'s own ordering does not change that',
    VersionTargets::plan([], ['FORGE_VERSION', 'BUILD_TYPE'], ['FORGE_VERSION', 'BUILD_TYPE']),
    ['FORGE_VERSION' => LOADER],
);

echo "\nThe profile's order wins, not the egg's:\n";

check(
    'egg lists them backwards',
    VersionTargets::plan(['MINECRAFT_VERSION', 'MC_VERSION'], ['FABRIC_VERSION', 'LOADER_VERSION'], ['LOADER_VERSION', 'MC_VERSION', 'FABRIC_VERSION', 'MINECRAFT_VERSION']),
    ['MINECRAFT_VERSION' => MC, 'FABRIC_VERSION' => LOADER],
);

echo "\nNothing to write:\n";

check('no loader names means no loader target', VersionTargets::plan(['MINECRAFT_VERSION'], [], ['MINECRAFT_VERSION', 'FORGE_VERSION']), ['MINECRAFT_VERSION' => MC]);
check('an egg with neither yields nothing', VersionTargets::plan(['MINECRAFT_VERSION'], ['FORGE_VERSION'], ['SERVER_JARFILE']), []);
check('no names at all yields nothing', VersionTargets::plan([], [], ['MINECRAFT_VERSION']), []);
check('an egg exposing nothing yields nothing', VersionTargets::plan(['MINECRAFT_VERSION'], ['FORGE_VERSION'], []), []);

echo "\nCase and collisions:\n";

check(
    'matching is case-insensitive but the egg\'s own casing is kept',
    VersionTargets::plan(['minecraft_version'], [], ['MineCraft_Version']),
    ['MineCraft_Version' => MC],
);

// One variable cannot hold both versions. Claiming it twice would mean the
// loader version silently overwriting the Minecraft version.
check(
    'a variable claimed by one role is not claimed by the other',
    VersionTargets::plan(['VERSION'], ['VERSION', 'FORGE_VERSION'], ['VERSION', 'FORGE_VERSION']),
    ['VERSION' => MC, 'FORGE_VERSION' => LOADER],
);

check(
    'and with no second option the loader simply goes unwritten',
    VersionTargets::plan(['VERSION'], ['VERSION'], ['VERSION']),
    ['VERSION' => MC],
);

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
