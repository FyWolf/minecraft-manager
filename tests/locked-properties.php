<?php

/**
 * Locked properties: the UI hint is not the enforcement — the save filter is.
 *
 *   php tests/locked-properties.php /path/to/panel
 *
 * Tests the filter with a FORGED submission: one where the client sent a new
 * value for a locked key despite the field being rendered disabled. That is
 * precisely what a `disabled` attribute cannot prevent, and on a host that
 * sells player slots it is the difference between a limit and a suggestion.
 */

$panel = $argv[1] ?? getenv('PELICAN_PANEL_PATH') ?: null;

if (! $panel || ! is_file($panel . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tests/locked-properties.php /path/to/panel\n");

    exit(2);
}

require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// The plugin's own PSR-4 root, as PluginService registers it at runtime.
spl_autoload_register(function (string $class) {
    $prefix = 'FyWolf\\MinecraftManager\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// Load this plugin's config: the panel under test need not have it installed.
config()->set('minecraft-manager', require dirname(__DIR__) . '/config/minecraft-manager.php');

use FyWolf\MinecraftManager\Support\PropertiesFile;

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
        echo "  FAIL $label\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n";
    }
}

config()->set('minecraft-manager.configs.locked_properties', ['max-players', 'custom-locked-key']);

$locked = (array) config('minecraft-manager.configs.locked_properties');

echo "Config:\n";
check('lock list loads', $locked, ['max-players', 'custom-locked-key']);

/**
 * Mirrors ConfigsPage::isLocked(). The page is a Livewire component and cannot
 * be instantiated standalone, so the rule itself is exercised here.
 */
$isLocked = function (string $key, array $spec = []) use ($locked): bool {
    if (! empty($spec['managed_by_panel'])) {
        return true;
    }

    return in_array($key, $locked, true);
};

$schema = (array) config('minecraft-manager.configs.properties_schema');

echo "\nWhich keys are locked:\n";
check('max-players locked by the admin list', $isLocked('max-players', $schema['max-players'] ?? []), true);
check('server-port locked by managed_by_panel', $isLocked('server-port', $schema['server-port'] ?? []), true);
check('difficulty stays editable', $isLocked('difficulty', $schema['difficulty'] ?? []), false);
check('motd stays editable', $isLocked('motd', $schema['motd'] ?? []), false);

// ---------------------------------------------------------------------------
// A forged save: the client sends max-players=200 for a server sold 20 slots.
// ---------------------------------------------------------------------------
$onDisk = <<<'PROPS'
#Minecraft server properties
max-players=20
difficulty=easy
motd=A Minecraft Server
server-port=25565
custom-locked-key=original
PROPS;

$properties = PropertiesFile::parse($onDisk);
$current = $properties->all();

$forged = [
    'max-players' => '200',        // locked by the admin list
    'server-port' => '31337',      // locked by managed_by_panel
    'custom-locked-key' => 'hax',  // locked, and NOT in the typed schema
    'difficulty' => 'hard',        // legitimate
    'motd' => 'Hacked',            // legitimate
];

$candidate = [];
$rejected = [];

foreach ($forged as $key => $submitted) {
    if ($isLocked($key, $schema[$key] ?? [])) {
        if (array_key_exists($key, $current) && $current[$key] !== $submitted) {
            $rejected[] = $key;
        }

        continue;
    }

    $candidate[$key] = $submitted;
}

$after = PropertiesFile::parse($properties->merge($candidate)->render())->all();

echo "\nForged submission:\n";
check('max-players unchanged on disk', $after['max-players'], '20');
check('server-port unchanged on disk', $after['server-port'], '25565');
check('locked key outside the schema unchanged', $after['custom-locked-key'], 'original');
check('legitimate change applied', $after['difficulty'], 'hard');
check('other legitimate change applied', $after['motd'], 'Hacked');
check('attempts recorded for the audit log', $rejected, ['max-players', 'server-port', 'custom-locked-key']);

echo "\nNo-op save (locked values submitted unchanged):\n";
$rejected2 = [];

foreach (['max-players' => '20', 'difficulty' => 'easy'] as $key => $submitted) {
    if ($isLocked($key, $schema[$key] ?? []) && array_key_exists($key, $current) && $current[$key] !== $submitted) {
        $rejected2[] = $key;
    }
}

check('no false "attempt" when nothing was changed', $rejected2, []);

echo "\n" . str_repeat('-', 46) . "\n$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
