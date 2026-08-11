<?php

namespace FyWolf\MinecraftManager\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared HTTP behaviour for every upstream this plugin talks to.
 *
 * Three rules, each of which the existing minecraft-modrinth plugin breaks:
 *
 *  - Send a descriptive User-Agent. Modrinth's terms ask for one so they can
 *    contact an operator whose integration misbehaves rather than blocking the
 *    IP outright. The existing plugin sends none.
 *
 *  - Cache successes only. The existing plugin caches its empty-array error
 *    result for thirty minutes, so a single network blip makes the mod list
 *    look empty for half an hour.
 *
 *  - Degrade visibly. A failure returns a marked-degraded result so the UI can
 *    say "CurseForge is unreachable" instead of "no results".
 */
abstract class ApiClient
{
    /** Short negative cache so a dead upstream is not re-probed on every render. */
    private const UNAVAILABLE_PREFIX = 'mcm:down:';

    abstract public function key(): string;

    abstract protected function baseUrl(): string;

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->withHeaders($this->headers())
            ->withUserAgent($this->userAgent())
            ->acceptJson()
            ->connectTimeout((int) config('minecraft-manager.http.connect_timeout', 4))
            ->timeout((int) config('minecraft-manager.http.timeout', 8))
            // throw: false — a failed response is a degraded result, not an
            // exception to unwind a page render.
            ->retry((int) config('minecraft-manager.http.retries', 2), 250, throw: false);
    }

    private function userAgent(): string
    {
        $version = '0.1.0';

        try {
            $manifest = plugin_path('minecraft-manager', 'plugin.json');

            if (is_readable($manifest)) {
                $version = json_decode((string) file_get_contents($manifest), true)['version'] ?? $version;
            }
        } catch (Throwable) {
            // Version is cosmetic; never let it break a request.
        }

        return sprintf('PelicanPanel-MinecraftManager/%s (+%s)', $version, config('app.url', 'https://pelican.dev'));
    }

    /**
     * GET a JSON document, or null if anything at all went wrong.
     *
     * @param array<string, mixed> $query
     *
     * @return array<mixed>|null
     */
    protected function getJson(string $path, array $query = []): ?array
    {
        if ($this->isMarkedDown()) {
            return null;
        }

        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable $exception) {
            $this->markDown('connection');

            Log::warning('minecraft-manager: ' . $this->key() . ' unreachable', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->decode($response, $path);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>|null
     */
    protected function postJson(string $path, array $payload): ?array
    {
        if ($this->isMarkedDown()) {
            return null;
        }

        try {
            $response = $this->request()->post($path, $payload);
        } catch (Throwable $exception) {
            $this->markDown('connection');

            Log::warning('minecraft-manager: ' . $this->key() . ' unreachable', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->decode($response, $path);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(Response $response, string $path): ?array
    {
        if ($response->status() === 429) {
            // Respect Retry-After when the upstream sends one, but never trip
            // the breaker for longer than a page render tolerates.
            $retryAfter = (int) $response->header('Retry-After');

            $this->markDown('rate-limited', max(5, min($retryAfter ?: 60, 300)));

            Log::warning('minecraft-manager: ' . $this->key() . ' rate limited', ['path' => $path]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('minecraft-manager: ' . $this->key() . ' request failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            // 4xx is our fault (a bad key, a bad parameter) and retrying will
            // not help, but it does not mean the service is down — so do not
            // trip the breaker for everyone else. 5xx does.
            if ($response->serverError()) {
                $this->markDown('server-error');
            }

            return null;
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    private function isMarkedDown(): bool
    {
        return cache()->has(self::UNAVAILABLE_PREFIX . $this->key());
    }

    private function markDown(string $reason, ?int $seconds = null): void
    {
        cache()->put(
            self::UNAVAILABLE_PREFIX . $this->key(),
            $reason,
            $seconds ?? (int) config('minecraft-manager.cache.unavailable', 60),
        );
    }

    public function downReason(): ?string
    {
        return cache()->get(self::UNAVAILABLE_PREFIX . $this->key());
    }

    /**
     * Cache a successful lookup only.
     *
     * @template T
     *
     * @param callable(): ?T $callback
     *
     * @return ?T
     */
    protected function remember(string $key, int $ttl, callable $callback)
    {
        $cached = cache()->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if ($value !== null) {
            cache()->put($key, $value, $ttl);
        }

        return $value;
    }
}
