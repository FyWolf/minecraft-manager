<?php

namespace FyWolf\MinecraftManager\Support;

/**
 * A Java `.properties` file that survives a round trip.
 *
 * Parsed into an ordered list of tagged lines rather than a flat map. That one
 * decision is the whole reason unknown keys are safe: the file is never rebuilt
 * from a map, so comments, blank lines, ordering, duplicate keys and keys this
 * plugin has never heard of all survive untouched. Changing a value rewrites
 * that one line in place and nothing else moves.
 *
 * `parse_ini_string()` is not used on purpose. It mangles values containing `=`,
 * chokes on an unquoted `#` or `;` mid-value, and treats `on`/`off`/`yes`/`no`/
 * `none` as reserved words — so an MOTD of "none" would silently become an
 * empty string, and a password containing `=` would be truncated.
 */
class PropertiesFile
{
    public const LINE_PAIR = 'pair';

    public const LINE_COMMENT = 'comment';

    public const LINE_BLANK = 'blank';

    /**
     * @param array<int, array{type: string, raw: string, key?: string, value?: string}> $lines
     */
    private function __construct(private array $lines) {}

    public static function parse(string $contents): self
    {
        $lines = [];

        // Normalise line endings for parsing; the writer re-emits \n.
        $raw = preg_split("/\r\n|\n|\r/", $contents) ?: [];

        foreach ($raw as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                $lines[] = ['type' => self::LINE_BLANK, 'raw' => $line];

                continue;
            }

            if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, '!')) {
                $lines[] = ['type' => self::LINE_COMMENT, 'raw' => $line];

                continue;
            }

            // A key ends at the first unescaped `=` or `:`. Everything after is
            // the value verbatim — including any further separators.
            $split = self::splitPair($trimmed);

            if ($split === null) {
                // A bare key with no separator is legal and means an empty
                // value. Anything genuinely unparseable is preserved as a
                // comment-like passthrough rather than dropped.
                $lines[] = ['type' => self::LINE_COMMENT, 'raw' => $line];

                continue;
            }

            [$key, $value] = $split;

            $lines[] = [
                'type' => self::LINE_PAIR,
                'raw' => $line,
                'key' => self::unescape($key),
                'value' => self::unescape($value),
            ];
        }

        // A trailing newline produces a final empty element; drop exactly one so
        // repeated read/write cycles do not grow the file.
        if ($lines !== [] && end($lines)['type'] === self::LINE_BLANK && end($lines)['raw'] === '') {
            array_pop($lines);
        }

        return new self($lines);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function splitPair(string $line): ?array
    {
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\') {
                // Skip the escaped character — `\=` and `\:` are part of the key.
                $i++;

                continue;
            }

            if ($char === '=' || $char === ':') {
                return [
                    rtrim(substr($line, 0, $i)),
                    ltrim(substr($line, $i + 1)),
                ];
            }
        }

        return null;
    }

    /**
     * Handle the escapes that actually occur in server.properties.
     *
     * Minecraft writes `\:` in level-seed and `§` for section signs in the
     * MOTD; both must survive being read into a form field and written back.
     */
    private static function unescape(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $value[$i];

                continue;
            }

            $next = $value[++$i];

            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'f' => "\f",
                'u' => (function () use ($value, &$i, $length) {
                    $hex = substr($value, $i + 1, 4);

                    if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                        $i += 4;

                        return mb_chr(hexdec($hex), 'UTF-8') ?: '';
                    }

                    return 'u';
                })(),
                default => $next,
            };
        }

        return $out;
    }

    /**
     * Escape only what must be escaped on a value.
     *
     * Deliberately minimal: over-escaping would rewrite lines the user never
     * touched and produce a noisy diff against what Minecraft itself writes.
     */
    private static function escapeValue(string $value): string
    {
        return str_replace(
            ['\\', "\n", "\r", "\t"],
            ['\\\\', '\\n', '\\r', '\\t'],
            $value,
        );
    }

    private static function escapeKey(string $key): string
    {
        return str_replace(
            ['\\', ' ', '=', ':', "\n"],
            ['\\\\', '\\ ', '\\=', '\\:', '\\n'],
            $key,
        );
    }

    public function has(string $key): bool
    {
        foreach ($this->lines as $line) {
            if ($line['type'] === self::LINE_PAIR && $line['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        // Last occurrence wins, matching java.util.Properties.
        $found = $default;

        foreach ($this->lines as $line) {
            if ($line['type'] === self::LINE_PAIR && $line['key'] === $key) {
                $found = $line['value'];
            }
        }

        return $found;
    }

    /**
     * Every key/value pair, in file order.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->lines as $line) {
            if ($line['type'] === self::LINE_PAIR) {
                $out[$line['key']] = $line['value'];
            }
        }

        return $out;
    }

    /**
     * Set a value, rewriting the existing line in place if the key is present
     * and appending only if it is genuinely new.
     */
    public function set(string $key, string $value): self
    {
        $seen = false;

        foreach ($this->lines as $index => $line) {
            if ($line['type'] !== self::LINE_PAIR || $line['key'] !== $key) {
                continue;
            }

            if ($seen) {
                // A duplicate key later in the file would override our edit, so
                // it has to go — but only after the first has been updated.
                unset($this->lines[$index]);

                continue;
            }

            $this->lines[$index]['value'] = $value;
            $this->lines[$index]['raw'] = self::escapeKey($key) . '=' . self::escapeValue($value);
            $seen = true;
        }

        if (! $seen) {
            $this->lines[] = [
                'type' => self::LINE_PAIR,
                'raw' => self::escapeKey($key) . '=' . self::escapeValue($value),
                'key' => $key,
                'value' => $value,
            ];
        }

        $this->lines = array_values($this->lines);

        return $this;
    }

    /**
     * @param array<string, string> $values
     */
    public function merge(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, (string) $value);
        }

        return $this;
    }

    public function render(): string
    {
        $out = [];

        foreach ($this->lines as $line) {
            $out[] = $line['type'] === self::LINE_PAIR
                ? self::escapeKey($line['key']) . '=' . self::escapeValue($line['value'])
                : $line['raw'];
        }

        // Trailing newline, as Minecraft writes it. No header comment is added:
        // Minecraft rewrites its own header on shutdown, so ours would only ever
        // create churn in the diff.
        return implode("\n", $out) . "\n";
    }

    /**
     * Keys whose value differs from the supplied set.
     *
     * Used to log *which* settings changed without ever logging their values —
     * rcon.password lives in this file.
     *
     * @param array<string, string> $candidate
     *
     * @return array<int, string>
     */
    public function changedKeys(array $candidate): array
    {
        $current = $this->all();
        $changed = [];

        foreach ($candidate as $key => $value) {
            if (! array_key_exists($key, $current) || $current[$key] !== (string) $value) {
                $changed[] = $key;
            }
        }

        return $changed;
    }
}
