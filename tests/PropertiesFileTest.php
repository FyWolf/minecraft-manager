<?php

require __DIR__ . '/../src/Support/PropertiesFile.php';

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
        echo "  FAIL $label\n";
        echo "       expected: " . var_export($expected, true) . "\n";
        echo "       actual:   " . var_export($actual, true) . "\n";
    }
}

// A realistic, nasty server.properties.
$original = <<<'PROPS'
#Minecraft server properties
#Mon Aug 11 10:00:00 UTC 2026
enable-jmx-monitoring=false
rcon.port=25575
level-seed=
motd=none
gamemode=survival
query.port=25565
pvp=true
difficulty=easy
max-players=20
some-unknown-future-key=hello
weird-value=a=b=c
level-name=world
rcon.password=p@ss=word

# trailing comment
duplicate-key=first
duplicate-key=second
PROPS;

$p = PropertiesFile::parse($original);

echo "Parsing:\n";
check('reads a plain value', $p->get('gamemode'), 'survival');
check('MOTD of "none" is the literal string, not empty', $p->get('motd'), 'none');
check('empty value parses as empty string', $p->get('level-seed'), '');
check('value containing = is not truncated', $p->get('weird-value'), 'a=b=c');
check('password containing = survives', $p->get('rcon.password'), 'p@ss=word');
check('boolean-ish value stays a string', $p->get('pvp'), 'true');
check('dotted key works', $p->get('query.port'), '25565');
check('unknown key is readable', $p->get('some-unknown-future-key'), 'hello');
check('last duplicate wins (java.util.Properties)', $p->get('duplicate-key'), 'second');
check('missing key returns default', $p->get('not-here', 'fallback'), 'fallback');
check('has() true for present', $p->has('motd'), true);
check('has() false for absent', $p->has('nope'), false);

echo "\nRound trip with no edits:\n";
$rendered = PropertiesFile::parse($original)->render();
check('comments survive', str_contains($rendered, '#Minecraft server properties'), true);
check('trailing comment survives', str_contains($rendered, '# trailing comment'), true);
check('unknown key survives', str_contains($rendered, 'some-unknown-future-key=hello'), true);
check('value with = survives', str_contains($rendered, 'weird-value=a=b=c'), true);
check('MOTD none survives', str_contains($rendered, 'motd=none'), true);

// Idempotency: parse(render(parse(x))) == render(parse(x))
$twice = PropertiesFile::parse($rendered)->render();
check('render is idempotent', $twice, $rendered);

echo "\nEditing:\n";
$edited = PropertiesFile::parse($original)->set('max-players', '40')->render();
check('changed value is written', str_contains($edited, 'max-players=40'), true);
check('old value is gone', str_contains($edited, 'max-players=20'), false);
check('untouched key unchanged', str_contains($edited, 'difficulty=easy'), true);
check('comments still present after edit', str_contains($edited, '#Minecraft server properties'), true);
check('unknown key still present after edit', str_contains($edited, 'some-unknown-future-key=hello'), true);

// Only the intended line should differ.
$before = explode("\n", $original);
$after = explode("\n", rtrim($edited, "\n"));
$diff = [];
foreach ($after as $i => $line) {
    if (($before[$i] ?? null) !== $line) {
        $diff[] = $line;
    }
}
check('exactly one line differs', count($diff), 1);
check('and it is the right one', $diff[0] ?? '', 'max-players=40');

echo "\nDuplicate collapse:\n";
$dup = PropertiesFile::parse($original)->set('duplicate-key', 'third')->render();
check('duplicate key collapses to one line', substr_count($dup, 'duplicate-key='), 1);
check('and holds the new value', str_contains($dup, 'duplicate-key=third'), true);

echo "\nNew keys:\n";
$added = PropertiesFile::parse($original)->set('brand-new', 'yes')->render();
check('new key is appended', str_contains($added, 'brand-new=yes'), true);

echo "\nEscapes:\n";
$esc = PropertiesFile::parse("a\\:b=c\\:d\nmulti=line\\nbreak\n");
check('escaped colon in key', $esc->has('a:b'), true);
check('escaped colon in value', $esc->get('a:b'), 'c:d');
check('escaped newline in value', $esc->get('multi'), "line\nbreak");
check('newline re-escapes on render', str_contains($esc->render(), 'multi=line\\nbreak'), true);

$uni = PropertiesFile::parse('motd=§aGreen');
check('unicode escape decodes', $uni->get('motd'), "\u{00A7}aGreen");

echo "\nColon separator:\n";
$colon = PropertiesFile::parse("key:value\nother : spaced\n");
check('colon acts as separator', $colon->get('key'), 'value');
check('spaces around separator trimmed', $colon->get('other'), 'spaced');

echo "\nchangedKeys:\n";
$p2 = PropertiesFile::parse($original);
check('detects a change', $p2->changedKeys(['max-players' => '40']), ['max-players']);
check('ignores an unchanged value', $p2->changedKeys(['max-players' => '20']), []);
check('treats a new key as changed', $p2->changedKeys(['brand-new' => 'x']), ['brand-new']);

echo "\nEdge cases:\n";
check('empty file renders empty-ish', PropertiesFile::parse('')->all(), []);
check('bare key with no separator is preserved verbatim', str_contains(PropertiesFile::parse("loneKey\n")->render(), 'loneKey'), true);
check('CRLF input parses', PropertiesFile::parse("a=1\r\nb=2\r\n")->get('b'), '2');

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
