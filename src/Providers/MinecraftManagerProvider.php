<?php

namespace FyWolf\MinecraftManager\Providers;

use App\Models\ApiKey;
use App\Models\Egg;
use App\Models\Role;
use FyWolf\MinecraftManager\Http\Controllers\Api\AddonController;
use FyWolf\MinecraftManager\Console\Commands\PruneStalePackInstallsCommand;
use FyWolf\MinecraftManager\Console\Commands\ReconcileAddonsCommand;
use FyWolf\MinecraftManager\Integrations\Content\ContentProviderRegistry;
use FyWolf\MinecraftManager\Integrations\Content\CurseForgeProvider;
use FyWolf\MinecraftManager\Integrations\Content\ModrinthProvider;
use FyWolf\MinecraftManager\Integrations\Versions\FabricProvider;
use FyWolf\MinecraftManager\Integrations\Versions\PaperProvider;
use FyWolf\MinecraftManager\Integrations\Versions\PurpurProvider;
use FyWolf\MinecraftManager\Integrations\Versions\VanillaProvider;
use FyWolf\MinecraftManager\Integrations\Versions\VersionProviderRegistry;
use FyWolf\MinecraftManager\Models\CapabilityProfile;
use FyWolf\MinecraftManager\Models\EggCapabilityProfile;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * The plugin's only service provider.
 *
 * `src/Providers/` MUST stay flat. `Plugin::getProviders()` runs a *recursive*
 * `File::allFiles()` and builds each class name by concatenating the relative
 * pathname onto the namespace, so a nested `src/Providers/Foo/Bar.php` becomes
 * the class string `FyWolf\MinecraftManager\Providers\Foo/Bar` — a forward slash
 * inside a class name. `class_exists()` returns false and the panel skips it
 * without a word. Anything that is not a ServiceProvider lives elsewhere:
 * API clients in `src/Integrations`, helpers in `src/Support`.
 */
class MinecraftManagerProvider extends ServiceProvider
{
    /**
     * This plugin's own application-ACL resource.
     *
     * Separate from the billing bridge's `billing`, because these routes claim
     * ports and therefore consume node capacity — a capability worth granting
     * independently of "provision game servers".
     */
    public const RESOURCE_NAME = 'minecraft';

    public function register(): void
    {
        ApiKey::registerCustomResourceName(self::RESOURCE_NAME);

        // Makes `mc_capability_profile` a first-class model permission, so admin
        // roles can be granted profile management without full panel access.
        Role::registerCustomDefaultPermissions('mc_capability_profile');
        Role::registerCustomModelIcon('mc_capability_profile', 'tabler-cube');

        Role::registerCustomDefaultPermissions('mc_addon');
        Role::registerCustomModelIcon('mc_addon', 'tabler-puzzle');

        // Singleton so the per-egg memo survives across the several components
        // that each ask "what can this server do?" while rendering one page.
        $this->app->singleton(CapabilityResolver::class);

        // Both providers are always registered; the registry filters by
        // isAvailable(), so CurseForge simply is not there until a key exists.
        // That single predicate is the whole auto-hide behaviour — no page needs
        // to know why a provider is missing.
        $this->app->singleton(ContentProviderRegistry::class, function () {
            return (new ContentProviderRegistry())
                ->register(new ModrinthProvider())
                ->register(new CurseForgeProvider());
        });

        // Note the absence of Forge and NeoForge. They publish an installer
        // rather than a runnable jar, so there is nothing here that could swap
        // one — their profiles carry a null version_provider and take the
        // reinstall path instead. PaperMC's API serves four projects, so one
        // class is registered under four keys.
        $this->app->singleton(VersionProviderRegistry::class, function () {
            return (new VersionProviderRegistry())
                ->register(new VanillaProvider())
                ->register(new PaperProvider('paper'))
                ->register(new PaperProvider('folia'))
                ->register(new PaperProvider('velocity'))
                ->register(new PaperProvider('waterfall'))
                ->register(new PurpurProvider())
                ->register(new FabricProvider());
        });
    }

    public function boot(): void
    {
        $this->registerEggRelation();
        $this->registerActivityStrings();
        $this->registerSchedule();
        $this->registerRoutes();
    }

    /**
     * The endpoints the billing service calls.
     *
     * The withoutMiddleware() list is mandatory boilerplate for a panel API
     * route: without it the global `web` group and CSRF verification apply, and
     * token authentication simply does not work.
     */
    private function registerRoutes(): void
    {
        Route::prefix('api/application/minecraft')
            ->middleware(['auth:sanctum', 'throttle:120,1'])
            ->withoutMiddleware(['web', 'auth', 'verify-csrf-token', 'App\Http\Middleware\VerifyCsrfToken'])
            ->group(function () {
                Route::get('/addons', [AddonController::class, 'catalogue'])->name('minecraft-manager.api.catalogue');
                Route::post('/addons', [AddonController::class, 'store'])->name('minecraft-manager.api.grant');
                Route::delete('/addons', [AddonController::class, 'destroy'])->name('minecraft-manager.api.revoke');
                Route::get('/servers/{server}/addons', [AddonController::class, 'index'])->name('minecraft-manager.api.list');
            });
    }

    /**
     * Without this, one `queue:restart` during a deploy permanently locks a
     * server out of modpack installs: the abandoned row stays non-terminal and
     * the one-install-per-server guard refuses everything afterwards.
     */
    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            Schedule::command(PruneStalePackInstallsCommand::class)
                ->hourly()
                ->withoutOverlapping();

            // Most mods write their config on first start, so an addon's port
            // usually cannot be written in until the customer has run the
            // server once. This finishes the job, and reclaims ports from
            // installs that died mid-provision.
            Schedule::command(ReconcileAddonsCommand::class)
                ->everyTenMinutes()
                ->withoutOverlapping();
        });
    }

    /**
     * Graft the profile relation onto the panel's own Egg model.
     *
     * The same technique player-counter uses for `gameQuery`. Note the relation
     * name is namespaced enough not to collide with it — two plugins resolving
     * the same relation name onto Egg would silently fight, last one booted
     * winning.
     */
    private function registerEggRelation(): void
    {
        Egg::resolveRelationUsing('mcCapabilityProfile', fn (Egg $egg) => $egg->hasOneThrough(
            CapabilityProfile::class,
            EggCapabilityProfile::class,
            'egg_id',
            'id',
            'id',
            'mc_capability_profile_id',
        ));
    }

    /**
     * Teach the activity feed how to render this plugin's events.
     *
     * `ActivityLog` renders an entry with
     * `trans_choice('activity.' . str($event)->replace(':', '.'), …)` — a lookup
     * in the *root* translation namespace. A plugin's `lang/` directory is
     * mounted as the namespaced `minecraft-manager::`, which that lookup never
     * consults, so a `lang/en/activity.php` file here would be ignored and every
     * event would render as its raw key. `Lang::addLines()` injects into the
     * root namespace at runtime, which is the only thing that works.
     */
    private function registerActivityStrings(): void
    {
        Lang::addLines([
            'activity.server.minecraft.world-archive' => 'Archived the world <b>:world</b> to <b>:archive</b>',
            'activity.server.minecraft.world-restore' => 'Restored the world <b>:world</b> from <b>:archive</b>',
            'activity.server.minecraft.world-switch' => 'Changed the active world from <b>:old</b> to <b>:new</b>',
            'activity.server.minecraft.world-delete' => 'Deleted the world <b>:world</b>',
            'activity.server.minecraft.world-reset' => 'Reset the world <b>:world</b>',
            'activity.server.minecraft.config-edit' => 'Edited <b>:file</b> (:changed)',
            'activity.server.minecraft.eula-accept' => 'Accepted the Minecraft EULA',
            'activity.server.minecraft.config-locked-rejected' => 'Attempted to change locked setting(s) <b>:keys</b> in <b>:file</b>',
            'activity.server.minecraft.content-install' => 'Installed <b>:name</b> (:version) from :provider into <b>:directory</b>',
            'activity.server.minecraft.content-delete' => 'Removed <b>:name</b> from <b>:directory</b>',
            'activity.server.minecraft.content-toggle' => 'Renamed <b>:name</b> in <b>:directory</b> to enable or disable it',
            'activity.server.minecraft.version-change' => 'Changed the server version to <b>:version</b> (:mode)',
            'activity.server.minecraft.modpack-install-start' => 'Started installing the modpack <b>:pack</b> (:version)',
            'activity.server.minecraft.modpack-install-finish' => 'Finished installing the modpack <b>:pack</b> — :installed of :total files',
            'activity.server.minecraft.modpack-install-failed' => 'Failed to install the modpack <b>:pack</b>: :error',
            'activity.server.minecraft.modpack-install-cancel' => 'Cancelled the modpack install <b>:pack</b>',
            'activity.server.minecraft.addon-install' => 'Installed the addon <b>:addon</b> on port <b>:port</b>',
            'activity.server.minecraft.addon-port-set' => 'Set <b>:addon</b> to listen on port <b>:port</b> in <b>:file</b>',
            'activity.server.minecraft.addon-revoke' => 'Withdrew the addon <b>:addon</b> and released port <b>:port</b>',
            'activity.server.minecraft.addon-uninstall' => 'Removed the addon <b>:addon</b>',
            'activity.server.minecraft.addon-failed' => 'Failed to install the addon <b>:addon</b>: :error',
        ], 'en');
    }
}
