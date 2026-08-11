<?php

namespace FyWolf\MinecraftManager\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use FyWolf\MinecraftManager\Enums\AddonState;
use FyWolf\MinecraftManager\Http\Requests\GrantAddonRequest;
use FyWolf\MinecraftManager\Http\Requests\ReadAddonRequest;
use FyWolf\MinecraftManager\Models\Addon;
use FyWolf\MinecraftManager\Models\ServerAddon;
use FyWolf\MinecraftManager\Services\AddonService;
use Illuminate\Http\JsonResponse;

/**
 * The endpoints the billing service calls, alongside the billing-bridge's own
 * under /api/application/, gated on an application API key.
 *
 * Billing owns the commerce and pushes the resulting facts here; nothing in the
 * customer-facing pages ever calls billing back. That is the same split
 * vcenter-vps uses, and the reason is the same: putting billing on the critical
 * path of a page render means an outage there takes the panel's Minecraft pages
 * down with it.
 *
 * Servers are addressed by uuid throughout — the identifier both sides had
 * before anything was provisioned.
 */
class AddonController extends Controller
{
    public function __construct(private readonly AddonService $addons) {}

    /**
     * Grant an addon.
     *
     * 202, not 201: claiming a port and downloading a mod is queued work, and
     * nothing is installed when this responds. Billing should treat it as
     * accepted and read state from a later show().
     */
    public function store(GrantAddonRequest $request): JsonResponse
    {
        [$server, $addon, $error] = $this->resolve($request->input('server'), $request->input('addon'));

        if ($error) {
            return $error;
        }

        $install = $this->addons->grant(
            $server,
            $addon,
            source: 'billing',
            reference: $request->input('reference'),
        );

        // Idempotent: a retry of a call billing never saw the response to must
        // not provision a second port.
        $already = $install->state === AddonState::Active;

        return response()->json(
            $this->format($install) + ['status' => $already ? 'already_active' : 'accepted'],
            $already ? 200 : 202,
        );
    }

    /**
     * Withdraw an addon.
     *
     * Releases the port immediately — that is the capacity being sold — and
     * leaves the mod's files in place, so a customer who resubscribes does not
     * lose a rendered map that took hours of CPU to build.
     */
    public function destroy(GrantAddonRequest $request): JsonResponse
    {
        [$server, $addon, $error] = $this->resolve($request->input('server'), $request->input('addon'));

        if ($error) {
            return $error;
        }

        $install = $this->addons->revoke($server, $addon);

        return response()->json($install ? $this->format($install) : ['status' => 'not_installed']);
    }

    /**
     * Everything this server has, so billing can reconcile.
     */
    public function index(ReadAddonRequest $request, string $server): JsonResponse
    {
        $model = Server::where('uuid', $server)->orWhere('uuid_short', $server)->first();

        if (! $model) {
            return response()->json(['error' => 'No such server.'], 404);
        }

        $installs = ServerAddon::with(['addon', 'allocation'])
            ->where('server_id', $model->id)
            ->get()
            ->map(fn (ServerAddon $install) => $this->format($install));

        return response()->json(['data' => $installs]);
    }

    /**
     * The catalogue, so billing can build its own product list from one source.
     */
    public function catalogue(ReadAddonRequest $request): JsonResponse
    {
        return response()->json([
            'data' => Addon::where('enabled', true)->orderBy('sort')->get()->map(fn (Addon $addon) => [
                'key' => $addon->key,
                'name' => $addon->name,
                'description' => $addon->description,
                'free' => $addon->free,
                'needs_port' => $addon->needs_port,
                'port_protocol' => $addon->port_protocol,
                'loaders' => $addon->loaders,
                'billing_sku' => $addon->billing_sku,
            ]),
        ]);
    }

    /**
     * @return array{0: ?Server, 1: ?Addon, 2: ?JsonResponse}
     */
    private function resolve(?string $serverKey, ?string $addonKey): array
    {
        $server = Server::where('uuid', $serverKey)->orWhere('uuid_short', $serverKey)->first();

        if (! $server) {
            return [null, null, response()->json(['error' => 'No such server.'], 404)];
        }

        $addon = Addon::where('key', $addonKey)->first();

        if (! $addon) {
            return [null, null, response()->json(['error' => 'No such addon.'], 404)];
        }

        if (! $addon->enabled) {
            return [null, null, response()->json(['error' => 'That addon is disabled.'], 409)];
        }

        return [$server, $addon, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ServerAddon $install): array
    {
        return [
            'addon' => $install->addon?->key,
            'state' => $install->state->value,
            'port' => $install->allocation?->port,
            'endpoint' => $install->endpoint(),
            'reference' => $install->billing_reference,
            'installed_at' => $install->installed_at?->toIso8601String(),
            'revoked_at' => $install->revoked_at?->toIso8601String(),
            'error' => $install->error,
        ];
    }
}
