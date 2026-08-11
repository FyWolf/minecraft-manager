<?php

/**
 * Addon port lifecycle against a real panel.
 *
 * The invariant that matters commercially: a port is claimed on grant and given
 * back on every exit path, including failure. A leak here consumes node
 * capacity that nobody is paying for.
 *
 * The mod download will fail — this machine has no outbound HTTPS — which is
 * convenient: it exercises the failure path for free.
 */

$dev = $argv[1] ?? getenv('PELICAN_PANEL_PATH') ?: null;

if (! $dev || ! is_file($dev . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tests/addon-lifecycle.php /path/to/panel
");
    fwrite(STDERR, "Needs a panel with the plugin installed and a server named test-paper.
");

    exit(2);
}

require $dev . '/vendor/autoload.php';
$app = require $dev . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Allocation;
use App\Models\Server;
use FyWolf\MinecraftManager\Enums\AddonState;
use FyWolf\MinecraftManager\Models\Addon;
use FyWolf\MinecraftManager\Models\ServerAddon;
use FyWolf\MinecraftManager\Services\AddonService;

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

// Auto-allocation must be on, with a port range, or claiming throws.
config()->set('panel.client_features.allocations.enabled', true);
config()->set('panel.client_features.allocations.create_new', true);
config()->set('panel.client_features.allocations.range_start', 26000);
config()->set('panel.client_features.allocations.range_end', 26050);

$server = Server::where('name', 'test-paper')->firstOrFail();
$addon = Addon::where('key', 'bluemap')->firstOrFail();

// Clean slate.
ServerAddon::where('server_id', $server->id)->delete();

$service = app(AddonService::class);

$portsBefore = Allocation::where('node_id', $server->node_id)->whereNotNull('server_id')->count();
$limitBefore = $server->allocation_limit;

echo "Before: server has " . $server->allocations()->count() . " allocation(s), limit " . var_export($limitBefore, true) . "\n\n";

echo "Grant:\n";
$install = $service->grant($server, $addon, source: 'billing', reference: 'ORDER-123');

check('record created', $install->exists, true);
check('state is pending', $install->state, AddonState::Pending);
check('source recorded', $install->source, 'billing');
check('billing reference recorded', $install->billing_reference, 'ORDER-123');

echo "\nIdempotency (billing retries a call it never saw answered):\n";
$again = $service->grant($server, $addon, source: 'billing', reference: 'ORDER-123');
check('same record returned, not a second one', $again->id, $install->id);
check('only one install row exists', ServerAddon::where('server_id', $server->id)->where('mc_addon_id', $addon->id)->count(), 1);

echo "\nProvision (mod download will fail — no outbound HTTPS here):\n";
$threw = null;

try {
    $service->provision($install->refresh());
} catch (Throwable $e) {
    $threw = $e->getMessage();
}

$install->refresh();
$server->refresh();

echo "  provision threw: " . ($threw ?? '(nothing)') . "\n";
check('a port WAS claimed before the failure', $install->allocation_id !== null, true);

if ($install->allocation_id) {
    $allocation = Allocation::find($install->allocation_id);
    echo "  claimed port: {$allocation->port} (server_id={$allocation->server_id})\n";
    check('the allocation is assigned to this server', $allocation->server_id, $server->id);
    check('port is inside the configured range', $allocation->port >= 26000 && $allocation->port <= 26050, true);
    // null is not "unlimited": the panel's own UI does count() >= limit, so a
    // null limit already disables self-service allocations. An explicit null is
    // the admin's choice and is deliberately left alone.
    check('a null allocation_limit is respected, not overwritten', $server->allocation_limit, null);
}

echo "\nFailure must not leak the port:\n";
$service->releasePort($install->refresh());
$install->refresh();
$server->refresh();

check('install no longer holds an allocation', $install->allocation_id, null);
check('allocation is unassigned again', Allocation::where('node_id', $server->node_id)->whereNotNull('server_id')->count(), $portsBefore);
check('null allocation_limit still untouched after release', $server->allocation_limit, null);

echo "
With an explicit allocation_limit set:
";
$server->forceFill(['allocation_limit' => 1])->saveOrFail();
$install2 = $service->grant($server, $addon, source: 'admin');

try {
    $service->provision($install2->refresh());
} catch (Throwable $e) {
    // Expected: the mod download cannot succeed here.
}

$install2->refresh();
$server->refresh();

check('port claimed', $install2->allocation_id !== null, true);
check('allocation_limit raised to cover the extra port', $server->allocation_limit, $server->allocations()->count());

$before = $server->allocation_limit;
$service->releasePort($install2->refresh());
$server->refresh();

check('allocation_limit lowered again on release', $server->allocation_limit, $before - 1);
check('but never below what is actually in use', $server->allocation_limit >= $server->allocations()->count(), true);

$server->forceFill(['allocation_limit' => null])->saveOrFail();

echo "\nRevoke keeps the files, releases the port:\n";
$install->forceFill(['state' => AddonState::Active, 'installed_file' => 'bluemap.jar'])->save();

// Give it a port again so revoke has something to reclaim.
$fresh = Allocation::withoutGlobalScopes()->where('node_id', $server->node_id)->whereNull('server_id')->first();

if ($fresh) {
    $fresh->update(['server_id' => $server->id]);
    $install->forceFill(['allocation_id' => $fresh->id])->save();
}

$service->revoke($server, $addon);
$install->refresh();

check('state is suspended, not removed', $install->state, AddonState::Suspended);
check('port released', $install->allocation_id, null);
check('the mod file is REMEMBERED, not deleted', $install->installed_file, 'bluemap.jar');
check('revoked_at stamped', $install->revoked_at !== null, true);

echo "\nPrimary allocation is never released:\n";
$install->forceFill(['allocation_id' => $server->allocation_id, 'state' => AddonState::Active])->save();
$service->releasePort($install->refresh());
$server->refresh();

check('server still has its primary allocation', $server->allocation_id !== null, true);
check('primary allocation still assigned', Allocation::find($server->allocation_id)?->server_id, $server->id);

// Tidy up.
ServerAddon::where('server_id', $server->id)->delete();

echo "\n" . str_repeat('-', 46) . "\n$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
