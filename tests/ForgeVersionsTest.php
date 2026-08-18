<?php

require __DIR__ . '/../src/Support/ForgeVersions.php';

use FyWolf\MinecraftManager\Support\ForgeVersions;

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

/*
 * A faithful slice of maven-metadata.xml, keeping every shape the real document
 * has and that a reasonable parser gets wrong:
 *
 *   - 1.15.2 listed DESCENDING, 26.2 listed ASCENDING, in one file
 *   - a second dash in the version (1.12.2-14.23.4.2720-4627)
 *   - a Minecraft version that is not 1.x (26.2, the calendar scheme)
 */
$xml = <<<'XML'
<metadata>
  <versioning>
    <versions>
      <version>1.15.2-31.2.57</version>
      <version>1.15.2-31.2.56</version>
      <version>1.15.2-31.2.4</version>
      <version>1.12.2-14.23.4.2720-4627</version>
      <version>26.2-65.0.0</version>
      <version>26.2-65.1.2</version>
      <version>26.2-65.1.10</version>
      <version>1.21-51.0.33</version>
      <version>notaversion</version>
      <version>-leadingdash</version>
      <version>trailingdash-</version>
    </versions>
  </versioning>
</metadata>
XML;

$grouped = ForgeVersions::parseMavenMetadata($xml);

echo "Parsing:\n";
check('groups by Minecraft version', array_keys($grouped), ['26.2', '1.21', '1.15.2', '1.12.2']);
check('a calendar-scheme version is not dropped', isset($grouped['26.2']), true);
check('a version with no dash is skipped', in_array('notaversion', array_keys($grouped), true), false);
check('a leading-dash version is skipped', in_array('', array_keys($grouped), true), false);
check('a trailing-dash version is skipped', isset($grouped['trailingdash']), false);

echo "\nOrdering — the document is not sorted and must not be trusted:\n";
// Listed newest-first upstream; must stay that way.
check('a descending block stays newest-first', $grouped['1.15.2'], ['31.2.57', '31.2.56', '31.2.4']);
// Listed oldest-first upstream; must be reversed. 65.1.10 > 65.1.2 is the
// numeric comparison a string sort gets backwards.
check('an ascending block is re-sorted', $grouped['26.2'], ['65.1.10', '65.1.2', '65.0.0']);
check('26.2 sorts newer than every 1.x', array_keys($grouped)[0], '26.2');
check('1.21 sorts newer than 1.15.2', array_search('1.21', array_keys($grouped), true) < array_search('1.15.2', array_keys($grouped), true), true);

echo "\nSplitting:\n";
check('splits on the first dash', ForgeVersions::split('1.15.2-31.2.4'), ['1.15.2', '31.2.4']);
check('keeps a second dash in the build', ForgeVersions::split('1.12.2-14.23.4.2720-4627'), ['1.12.2', '14.23.4.2720-4627']);
check('a multi-dash build survives grouping', $grouped['1.12.2'], ['14.23.4.2720-4627']);
check('a calendar-scheme artifact splits', ForgeVersions::split('26.2-65.1.2'), ['26.2', '65.1.2']);
check('rebuilds the artifact', ForgeVersions::artifact('1.15.2', '31.2.4'), '1.15.2-31.2.4');

echo "\nPromotions:\n";
$promotions = ForgeVersions::parsePromotions([
    'homepage' => 'https://files.minecraftforge.net/',
    'promos' => [
        '1.15.2-latest' => '31.2.57',
        '1.15.2-recommended' => '31.2.0',
        '26.2-latest' => '65.1.2',
        'garbage' => 'x',
        '1.16.5-something' => 'y',
    ],
]);

check('reads recommended and latest', $promotions['1.15.2'], ['recommended' => '31.2.0', 'latest' => '31.2.57']);
check('a version with only latest still parses', $promotions['26.2'], ['recommended' => null, 'latest' => '65.1.2']);
check('an unkeyed entry is ignored', isset($promotions['garbage']), false);
check('an unknown promotion kind is ignored', isset($promotions['1.16.5']), false);
check('a payload with no promos is empty', ForgeVersions::parsePromotions(['nope' => 1]), []);

echo "\nLabels — the artifact leads, because that is the value being written:\n";
check('plain build', ForgeVersions::label('1.15.2', '31.2.4', $promotions['1.15.2']), '1.15.2-31.2.4');
check('recommended is marked', ForgeVersions::label('1.15.2', '31.2.0', $promotions['1.15.2']), '1.15.2-31.2.0 — recommended');
check('latest is marked', ForgeVersions::label('1.15.2', '31.2.57', $promotions['1.15.2']), '1.15.2-31.2.57 — latest');
check('no promotions is fine', ForgeVersions::label('1.15.2', '31.2.4', null), '1.15.2-31.2.4');

echo "\nWhich spelling the egg wants:\n";
check('a full artifact means full', ForgeVersions::wantsFullArtifact('1.15.2-31.2.4'), true);
check('a bare build means bare', ForgeVersions::wantsFullArtifact('31.2.4'), false);
check('empty falls back to full', ForgeVersions::wantsFullArtifact(''), true);
check('null falls back to full', ForgeVersions::wantsFullArtifact(null), true);
check('whitespace is not a value', ForgeVersions::wantsFullArtifact('   '), true);
// The Forge egg's other documented value. It has no dash, so it reads as bare —
// which is right: an egg whose FORGE_VERSION says "latest" is one that builds
// the URL itself from the Minecraft version.
check('a keyword reads as bare', ForgeVersions::wantsFullArtifact('latest'), false);

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
