<?php

namespace FyWolf\MinecraftManager\Support;

/**
 * Write an allocated port into a mod's own configuration file.
 *
 * Every one of these files belongs to the customer — they will have edited it,
 * and it is full of the mod author's explanatory comments. So nothing here ever
 * parses-and-regenerates a file: each format rewrites the single line holding
 * the port and leaves every other byte alone. That rules out symfony/yaml for
 * the YAML cases, which would round-trip correctly but silently strip every
 * comment in a Geyser config.
 *
 * Formats:
 *
 *   properties   Java .properties (Simple Voice Chat). Delegates to
 *                PropertiesFile, which already round-trips these safely.
 *
 *   line         The first `key:` or `key=` line at any indent, anywhere in the
 *                file (BlueMap's HOCON, Dynmap's configuration.txt). Good
 *                enough because the key is unique in those files.
 *
 *   yaml_section The first `key:` line *inside* a named top-level section
 *                (Geyser's `bedrock:` → `port:`). Needed because `port:` alone
 *                appears several times in a Geyser config and patching the
 *                wrong one silently changes the Java listener instead.
 */
class PortPatcher
{
    public const PLACEHOLDER = '%PORT%';

    /**
     * Apply the port to a config file's contents.
     *
     * @param array<string, mixed> $spec the addon's port_patch definition
     *
     * @return string|null the new contents, or null if the key could not be found
     */
    public static function apply(string $contents, array $spec, int $port): ?string
    {
        $key = (string) ($spec['key'] ?? 'port');

        return match ($spec['format'] ?? 'line') {
            'properties' => self::applyProperties($contents, $key, $port),
            'yaml_section' => self::applySection($contents, (string) ($spec['section'] ?? ''), $key, $port),
            default => self::applyLine($contents, $key, $port),
        };
    }

    /**
     * The contents to write when the mod has not generated its config yet.
     *
     * Returns null when the addon defines no stub, which means "wait for the mod
     * to write its own config and patch it afterwards" — the honest answer for
     * formats where a partial file would be worse than no file.
     *
     * @param array<string, mixed> $spec
     */
    public static function stub(array $spec, int $port): ?string
    {
        $stub = $spec['stub'] ?? null;

        return is_string($stub) ? str_replace(self::PLACEHOLDER, (string) $port, $stub) : null;
    }

    private static function applyProperties(string $contents, string $key, int $port): ?string
    {
        $properties = PropertiesFile::parse($contents);

        if (! $properties->has($key)) {
            return null;
        }

        return $properties->set($key, (string) $port)->render();
    }

    /**
     * Replace the value on the first line whose key matches, preserving that
     * line's indentation and separator.
     */
    private static function applyLine(string $contents, string $key, int $port): ?string
    {
        $lines = preg_split("/(\r\n|\n|\r)/", $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $quoted = preg_quote($key, '/');
        $patched = false;

        foreach ($lines as $index => $line) {
            // Odd indices are the captured line endings.
            if ($index % 2 === 1 || $patched) {
                continue;
            }

            // Never touch a commented-out example line.
            if (preg_match('/^\s*[#!]/', $line)) {
                continue;
            }

            if (preg_match('/^(\s*"?' . $quoted . '"?\s*[:=]\s*).*$/', $line, $m)) {
                $lines[$index] = $m[1] . $port;
                $patched = true;
            }
        }

        return $patched ? implode('', $lines) : null;
    }

    /**
     * Replace `key:` within a named top-level section.
     *
     * The section is a line at column zero ending in a colon; its body is every
     * following indented line. Only the first matching key inside it is
     * rewritten.
     */
    private static function applySection(string $contents, string $section, string $key, int $port): ?string
    {
        if ($section === '') {
            return self::applyLine($contents, $key, $port);
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $quotedSection = preg_quote($section, '/');
        $quotedKey = preg_quote($key, '/');

        $inSection = false;
        $patched = false;

        foreach ($lines as $index => $line) {
            if ($index % 2 === 1 || $patched) {
                continue;
            }

            if (preg_match('/^\s*[#!]/', $line) || trim($line) === '') {
                continue;
            }

            // A new top-level key ends the section we were in.
            if (preg_match('/^\S/', $line)) {
                $inSection = (bool) preg_match('/^"?' . $quotedSection . '"?\s*:/', $line);

                continue;
            }

            if ($inSection && preg_match('/^(\s*"?' . $quotedKey . '"?\s*[:=]\s*).*$/', $line, $m)) {
                $lines[$index] = $m[1] . $port;
                $patched = true;
            }
        }

        return $patched ? implode('', $lines) : null;
    }
}
