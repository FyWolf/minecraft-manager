<?php

namespace FyWolf\MinecraftManager;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minecraft server management, gated per egg.
 *
 * Four server-panel pages — Worlds, Configs, Versions, Content — none of which
 * render unless the server's egg resolves to a capability profile that grants
 * them. A Vanilla egg gets worlds/configs/versions and no mod browser; Paper
 * gets a plugin browser pointed at `plugins/`; Fabric gets mods and modpacks
 * pointed at `mods/`. An egg that resolves to nothing shows nothing at all,
 * which is the correct outcome for a custom egg we know nothing about.
 *
 * The gating lives in a DB table an admin can edit, not in hardcoded tag
 * checks. That is deliberate: the existing `minecraft-modrinth` plugin gates on
 * egg *features* named `mods` / `plugins` / `modrinth_mods` / `modrinth_plugins`,
 * and none of those are in the panel's registered feature-schema list — only
 * `eula`, `java_version`, `gsl_token`, `pid_limit` and `steam_disk_space` are.
 * So on a stock panel its page never appears unless someone hand-edits egg
 * features. See CapabilityResolver for what we do instead.
 */
class MinecraftManagerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'minecraft-manager';
    }

    public function register(Panel $panel): void
    {
        // 'server' -> 'Server', 'admin' -> 'Admin'. One line serves every panel;
        // the directory layout does the routing and missing directories are
        // harmless.
        $id = str($panel->getId())->title();

        $panel->discoverResources(
            plugin_path($this->getId(), "src/Filament/$id/Resources"),
            "FyWolf\\MinecraftManager\\Filament\\$id\\Resources",
        );

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "FyWolf\\MinecraftManager\\Filament\\$id\\Pages",
        );

        $panel->discoverWidgets(
            plugin_path($this->getId(), "src/Filament/$id/Widgets"),
            "FyWolf\\MinecraftManager\\Filament\\$id\\Widgets",
        );
    }

    public function boot(Panel $panel): void {}

    /**
     * @return array<int, mixed>
     */
    public function getSettingsForm(): array
    {
        return [
            Section::make('CurseForge')
                ->description('Optional. Without a key the CurseForge tab does not appear anywhere and everything else keeps working — Modrinth needs no credentials.')
                ->schema([
                    TextInput::make('curseforge_api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->helperText('Request one at console.curseforge.com. Leave blank to keep the current key.')
                        ->default(fn () => config('minecraft-manager.curseforge.api_key')),

                    Toggle::make('curseforge_clear_key')
                        ->label('Remove the stored key')
                        ->helperText('Saving with a blank field keeps the existing key, so this is the only way to delete one.')
                        ->default(false),

                    Actions::make([
                        Action::make('test_curseforge')
                            ->label('Test key')
                            ->icon('tabler-plug-connected')
                            ->action(function (Get $get): void {
                                // Test the key currently typed into the form, falling
                                // back to the stored one, so an admin can verify
                                // before committing.
                                $key = $get('curseforge_api_key') ?: config('minecraft-manager.curseforge.api_key');

                                if (blank($key)) {
                                    Notification::make()
                                        ->title('No key to test')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                try {
                                    $response = Http::withHeaders(['x-api-key' => $key])
                                        ->acceptJson()
                                        ->connectTimeout(4)
                                        ->timeout(8)
                                        ->get(rtrim((string) config('minecraft-manager.curseforge.base_url'), '/') . '/games/' . config('minecraft-manager.curseforge.game_id'));

                                    if ($response->successful()) {
                                        Notification::make()
                                            ->title('CurseForge key works')
                                            ->body('Reached ' . ($response->json('data.name') ?? 'the API') . '.')
                                            ->success()
                                            ->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title('CurseForge rejected the key')
                                        ->body('HTTP ' . $response->status() . ($response->status() === 403 ? ' — the key is invalid or not approved for this game.' : ''))
                                        ->danger()
                                        ->send();
                                } catch (Throwable $e) {
                                    Notification::make()
                                        ->title('Could not reach CurseForge')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }),
                    ]),
                ]),

            Section::make('Egg detection')
                ->description('How a server decides which pages it gets.')
                ->schema([
                    Toggle::make('heuristics_enabled')
                        ->label('Detect unmapped eggs automatically')
                        ->helperText('When an egg has no explicit profile, guess one from its tags and features. Turn this off to show the plugin only on eggs an admin has mapped by hand.')
                        ->default(fn () => (bool) config('minecraft-manager.heuristics.enabled', true)),

                    Placeholder::make('profiles_hint')
                        ->label('Per-egg mapping')
                        ->content('Admin → Minecraft Profiles. Run `php artisan minecraft-manager:sync-profiles` after importing new eggs.'),
                ]),

            Section::make('Locked settings')
                ->description('Properties customers can see but not change on the Configuration page.')
                ->schema([
                    TagsInput::make('locked_properties')
                        ->label('Locked server.properties keys')
                        ->placeholder('max-players')
                        ->helperText('The usual case is max-players, since on a host that sells slots the player limit belongs to the order rather than to the server. Locked keys stay visible but disabled, and are refused server-side — not merely greyed out in the browser.')
                        ->default(fn () => (array) config('minecraft-manager.configs.locked_properties', [])),

                    TextInput::make('locked_reason')
                        ->label('Reason shown to the customer')
                        ->placeholder('Set by your plan — contact support to change it.')
                        ->default(fn () => config('minecraft-manager.configs.locked_reason')),
                ]),

            Section::make('Modpacks')
                ->description('Modpack installs run on a queue worker.')
                ->schema([
                    TextInput::make('packs_queue')
                        ->label('Queue name')
                        ->placeholder('default')
                        ->helperText('A large pack install occupies a worker for many minutes. On the default queue that blocks every other panel job — backups, webhooks. Give it a dedicated name and run `php artisan queue:work --queue=<name>`.')
                        ->default(fn () => config('minecraft-manager.packs.queue', 'default')),
                ]),
        ];
    }

    /**
     * Current values for the settings slide-over.
     *
     * Keys MUST be the form field names, not config keys — the panel passes this
     * straight to `->fillForm()`, which replaces the schema's `->default()`
     * values rather than merging with them. Returning `config('minecraft-manager')`
     * wholesale compiles fine, fills nothing, and renders an empty form that
     * saves blanks over a working configuration.
     *
     * Older panel builds do not call this at all (their `HasPluginSettings`
     * declares only `getSettingsForm()` and `saveSettings()`), which is why every
     * field above also carries its own `->default()`. Both paths are correct.
     *
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            // Never echo the stored key back into the form — the field is
            // write-only, and a blank submission is understood as "unchanged".
            'curseforge_api_key' => null,
            'curseforge_clear_key' => false,
            'heuristics_enabled' => (bool) config('minecraft-manager.heuristics.enabled', true),
            'packs_queue' => config('minecraft-manager.packs.queue', 'default'),
            'locked_properties' => (array) config('minecraft-manager.configs.locked_properties', []),
            'locked_reason' => config('minecraft-manager.configs.locked_reason'),
        ];
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public function saveSettings(array $data): void
    {
        // Property keys contain dots and dashes but never commas, so a comma
        // separated list round-trips safely through .env. Written even when
        // empty, since clearing the list is a legitimate change.
        $locked = collect((array) ($data['locked_properties'] ?? []))
            ->map(fn ($key) => trim((string) $key))
            ->filter()
            ->unique()
            ->implode(',');

        $env = [
            'MCM_HEURISTICS' => ! empty($data['heuristics_enabled']) ? 'true' : 'false',
            'MCM_PACKS_QUEUE' => trim((string) ($data['packs_queue'] ?? '')) ?: 'default',
            'MCM_LOCKED_PROPERTIES' => $locked,
            'MCM_LOCKED_REASON' => trim((string) ($data['locked_reason'] ?? '')) ?: 'Set by your plan — contact support to change it.',
        ];

        if (! empty($data['curseforge_clear_key'])) {
            $env['CURSEFORGE_API_KEY'] = '';
        } elseif (filled($data['curseforge_api_key'] ?? null)) {
            // Only written when something was actually typed, so re-saving the
            // form for an unrelated reason cannot blank a working key.
            $env['CURSEFORGE_API_KEY'] = trim((string) $data['curseforge_api_key']);
        }

        $this->writeToEnvironment($env);

        // Note: the panel wraps this whole method in `try { … } catch (Exception) {}`
        // (Plugin::saveSettings), so a throw here would produce no feedback at
        // all — the slide-over would just close. Anything the admin needs to
        // know has to be sent as a notification.
        Notification::make()
            ->title('Minecraft Manager settings saved')
            ->success()
            ->send();
    }

    public static function make(): static
    {
        return app(static::class);
    }
}
