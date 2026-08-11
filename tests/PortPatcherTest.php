<?php

/**
 * Port patching, against config files shaped like the real ones.
 *
 *   php tests/PortPatcherTest.php
 *
 * These files belong to the customer and are full of the mod author's comments,
 * so the bar is: change the one line holding the port, and nothing else.
 */

require __DIR__ . '/../src/Support/PropertiesFile.php';
require __DIR__ . '/../src/Support/PortPatcher.php';

use FyWolf\MinecraftManager\Support\PortPatcher;

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

/** Lines that differ between two versions of a file. */
function diffLines(string $before, string $after): array
{
    $a = explode("\n", $before);
    $b = explode("\n", $after);
    $out = [];

    foreach ($b as $i => $line) {
        if (($a[$i] ?? null) !== $line) {
            $out[] = trim($line);
        }
    }

    return $out;
}

// ---------------------------------------------------------------- BlueMap
echo "BlueMap (HOCON, format=line):\n";

$bluemap = <<<'CONF'
## ##
## BlueMap Webserver Config
## ##

# Enable the integrated webserver
enabled: true

# The port the webserver listens on
port: 8100

# The maximum number of simultaneous connections
max-connection-count: 100
CONF;

$spec = ['format' => 'line', 'key' => 'port'];
$out = PortPatcher::apply($bluemap, $spec, 25566);

check('port rewritten', str_contains((string) $out, 'port: 25566'), true);
check('old port gone', str_contains((string) $out, '8100'), false);
check('exactly one line changed', diffLines($bluemap, (string) $out), ['port: 25566']);
check('comments preserved', str_contains((string) $out, '# The port the webserver listens on'), true);
check('max-connection-count untouched', str_contains((string) $out, 'max-connection-count: 100'), true);

// The trap: a commented-out example of the same key earlier in the file.
$commented = "# port: 9999\nport: 8100\n";
$out2 = PortPatcher::apply($commented, $spec, 25566);
check('commented-out example is not patched', $out2, "# port: 9999\nport: 25566\n");

check('missing key reports failure', PortPatcher::apply("enabled: true\n", $spec, 25566), null);

// ------------------------------------------------------- Simple Voice Chat
echo "\nSimple Voice Chat (.properties, format=properties):\n";

$voice = <<<'PROPS'
#Simple Voice Chat server config
port=24454
bind_address=
max_voice_distance=48.0
PROPS;

$voiceSpec = ['format' => 'properties', 'key' => 'port'];
$vOut = PortPatcher::apply($voice, $voiceSpec, 25567);

check('port rewritten', str_contains((string) $vOut, 'port=25567'), true);
check('other keys preserved', str_contains((string) $vOut, 'max_voice_distance=48.0'), true);
check('empty value preserved', str_contains((string) $vOut, 'bind_address='), true);
check('comment preserved', str_contains((string) $vOut, '#Simple Voice Chat server config'), true);

// ---------------------------------------------------------------- Geyser
echo "\nGeyser (YAML, format=yaml_section — the real trap):\n";

$geyser = <<<'YML'
# Geyser configuration
bedrock:
  # The IP address that will listen for connections
  address: 0.0.0.0
  # The port that will listen for connections
  port: 19132
  clone-remote-port: false
remote:
  address: auto
  # The port of the Java server
  port: 25565
  auth-type: online
YML;

$geyserSpec = ['format' => 'yaml_section', 'section' => 'bedrock', 'key' => 'port'];
$gOut = (string) PortPatcher::apply($geyser, $geyserSpec, 25568);

check('patches the bedrock port', str_contains($gOut, "  port: 25568"), true);
check('leaves the remote (Java) port alone', str_contains($gOut, "  port: 25565"), true);
check('exactly one line changed', diffLines($geyser, $gOut), ['port: 25568']);
check('does not touch clone-remote-port', str_contains($gOut, 'clone-remote-port: false'), true);
check('comments preserved', str_contains($gOut, '# The port of the Java server'), true);

// Order reversed: remote first. A naive "first port:" would patch the wrong one.
$reversed = "remote:\n  port: 25565\nbedrock:\n  port: 19132\n";
$rOut = (string) PortPatcher::apply($reversed, $geyserSpec, 25568);
check('section-aware even when bedrock comes second', $rOut, "remote:\n  port: 25565\nbedrock:\n  port: 25568\n");

check('missing section reports failure', PortPatcher::apply("remote:\n  port: 25565\n", $geyserSpec, 25568), null);

// ---------------------------------------------------------------- Dynmap
echo "\nDynmap (configuration.txt, format=line):\n";

$dynmap = <<<'TXT'
# Dynmap configuration
components:
  - class: org.dynmap.InternalClientUpdateComponent
webserver-port: 8123
webserver-bindaddress: 0.0.0.0
TXT;

$dOut = (string) PortPatcher::apply($dynmap, ['format' => 'line', 'key' => 'webserver-port'], 25569);

check('port rewritten', str_contains($dOut, 'webserver-port: 25569'), true);
check('bindaddress untouched', str_contains($dOut, 'webserver-bindaddress: 0.0.0.0'), true);
check('exactly one line changed', diffLines($dynmap, $dOut), ['webserver-port: 25569']);

// ------------------------------------------------------------------ misc
echo "\nGeneral:\n";

check('CRLF files keep their line endings', PortPatcher::apply("port: 1\r\nx: 2\r\n", $spec, 99), "port: 99\r\nx: 2\r\n");
check('= separator works too', PortPatcher::apply("port = 1\n", $spec, 99), "port = 99\n");
check('stub renders the port', PortPatcher::stub(['stub' => "port=%PORT%\n"], 25570), "port=25570\n");
check('no stub means no stub', PortPatcher::stub([], 25570), null);
check('similarly-named key not matched', PortPatcher::apply("port-forward: 1\n", $spec, 99), null);

echo "\n" . str_repeat('-', 46) . "\n$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
