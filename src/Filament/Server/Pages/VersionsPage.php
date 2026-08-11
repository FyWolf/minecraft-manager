<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Services\Servers\ReinstallServerService;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Services\ContentInstallService;
use FyWolf\MinecraftManager\Services\VersionInstallService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Throwable;

/**
 * Change the server's Minecraft version.
 *
 * Which of the two mechanisms is offered comes from the capability profile:
 *
 *  - A profile with a version provider gets a jar swap. Nothing but the jar is
 *    touched, so worlds, mods and configuration are untouched by construction.
 *  - A profile without one gets variable + reinstall, because its loader ships
 *    an installer rather than a runnable jar and only the egg's install script
 *    knows what to do with it.
 *
 * If a provider is merely *unreachable*, the page falls back to the reinstall
 * path with a banner rather than showing an empty version list — degrade toward
 * the thing that always works.
 */
class VersionsPage extends Page
{
    use BlockAccessInConflict;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-versions';

    protected static ?string $slug = 'mc-version';

    protected static ?int $navigationSort = 24;

    /** @var array<string, mixed> */
    public array $data = [];

    private ?ResolvedProfile $profileMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Versions)
            && user()?->can(SubuserPermission::StartupRead, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-manager::strings.nav.versions');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupRead, $this->server()), 403);

        $this->form->fill([
            'game_version' => $this->currentVersion(),
        ]);
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->server());
    }

    private function versions(): VersionInstallService
    {
        return app(VersionInstallService::class);
    }

    private function currentVersion(): ?string
    {
        return app(ContentInstallService::class)->minecraftVersion($this->server(), $this->profile());
    }

    private function isRunning(): bool
    {
        return ! $this->server()->retrieveStatus()->isOffline();
    }

    /**
     * Whether the jar-swap path is actually usable right now.
     *
     * Both conditions matter: a profile with no provider never swaps, and a
     * provider whose upstream is down would otherwise render an empty select
     * that looks like the plugin is broken.
     */
    private function canSwapJar(): bool
    {
        $provider = $this->versions()->providerFor($this->profile());

        return $provider !== null && $provider->gameVersions() !== [];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('mcm_current')
                    ->label('Current version')
                    ->state(fn () => $this->currentVersion() ?? 'not set')
                    ->badge(),

                TextEntry::make('mcm_loader')
                    ->label('Software')
                    ->state(fn () => $this->profile()->loader?->getLabel() ?? 'unknown')
                    ->badge()
                    ->color('gray'),

                TextEntry::make('mcm_method')
                    ->label('Method')
                    ->state(fn () => $this->canSwapJar() ? 'Replace the jar' : 'Reinstall')
                    ->badge()
                    ->color($this->canSwapJar() ? 'success' : 'warning'),

                TextEntry::make('mcm_state')
                    ->label('Server')
                    ->state(fn () => $this->isRunning() ? 'Running' : 'Stopped')
                    ->badge()
                    ->color(fn () => $this->isRunning() ? 'warning' : 'success'),
            ]),

            $this->canSwapJar() ? $this->jarSection() : $this->reinstallSection(),
        ]);
    }

    private function jarSection(): Section
    {
        $provider = $this->versions()->providerFor($this->profile());

        return Section::make('Change version')
            ->description('Downloads the chosen build over the existing server jar. Worlds, mods and configuration are not touched.')
            ->schema([
                Select::make('game_version')
                    ->label('Minecraft version')
                    ->options(fn () => array_combine($provider->gameVersions(), $provider->gameVersions()))
                    ->searchable()
                    ->live()
                    // A stale build id from the previous version would resolve
                    // to a 404 at pull time.
                    ->afterStateUpdated(fn (Set $set) => $set('build', null))
                    ->required(),

                Select::make('build')
                    ->label('Build')
                    ->options(fn (Get $get) => filled($get('game_version'))
                        ? collect($provider->builds((string) $get('game_version')))
                            ->mapWithKeys(fn (array $build) => [$build['id'] => $build['label']])
                            ->all()
                        : [])
                    ->searchable()
                    ->required()
                    ->helperText('Newest first.'),

                Checkbox::make('archive_first')
                    ->label('Archive the current jar first')
                    ->default(true)
                    ->helperText('About 50 MB, and makes rolling back instant.'),
            ])
            ->footerActions([$this->swapAction()])
            ->statePath('data');
    }

    private function reinstallSection(): Section
    {
        $profile = $this->profile();
        $providerConfigured = filled($profile->versionProvider);

        return Section::make('Change version')
            ->description($providerConfigured
                ? 'The version service for this software is unreachable, so the jar cannot be downloaded directly. Changing the version will re-run the egg\'s install script instead.'
                : 'This software is distributed as an installer rather than a ready-to-run jar, so the version is changed by updating the startup variable and reinstalling.')
            ->schema([
                Select::make('game_version')
                    ->label('Minecraft version')
                    ->options(fn () => $this->reinstallVersionOptions())
                    ->searchable()
                    // The egg may not constrain the value, so allow a typed one.
                    ->allowHtml(false)
                    ->required()
                    ->helperText('Must be a version this egg\'s install script understands.'),
            ])
            ->footerActions([$this->reinstallAction()])
            ->statePath('data');
    }

    /**
     * Offer the egg's own allowed values when its variable rules constrain
     * them; otherwise fall back to a generic list from the Vanilla manifest.
     *
     * @return array<string, string>
     */
    private function reinstallVersionOptions(): array
    {
        $candidates = $this->profile()->mcVersionVariables ?: ['MINECRAFT_VERSION'];

        foreach ($this->server()->variables as $variable) {
            if (! in_array(strtoupper((string) $variable->env_variable), array_map('strtoupper', $candidates), true)) {
                continue;
            }

            foreach ((array) ($variable->rules ?? []) as $rule) {
                if (is_string($rule) && str_starts_with($rule, 'in:')) {
                    $values = explode(',', substr($rule, 3));

                    return array_combine($values, $values);
                }
            }
        }

        $vanilla = app(\FyWolf\MinecraftManager\Integrations\Versions\VanillaProvider::class)->gameVersions();

        return $vanilla === [] ? [] : array_combine($vanilla, $vanilla);
    }

    private function swapAction(): Action
    {
        return Action::make('swap')
            ->label('Install this version')
            ->icon('tabler-download')
            ->disabled(fn () => $this->isRunning())
            ->tooltip(fn () => $this->isRunning() ? 'Stop the server first.' : null)
            ->requiresConfirmation()
            ->modalHeading('Replace the server jar')
            ->modalDescription('The jar is replaced and the version startup variable updated. Your worlds, mods and configuration are untouched.')
            ->action(function () {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);
                abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);

                if ($this->isRunning()) {
                    Notification::make()
                        ->title('Stop the server first')
                        ->body('Replacing the jar under a running JVM leaves it running the old code until restart, which is confusing at best.')
                        ->danger()
                        ->send();

                    return;
                }

                $state = $this->form->getState();
                $gameVersion = (string) ($state['game_version'] ?? '');
                $build = (string) ($state['build'] ?? '');

                if ($gameVersion === '' || $build === '') {
                    Notification::make()->title('Choose a version and a build')->warning()->send();

                    return;
                }

                // Check the docker image BEFORE downloading: refusing early is
                // better than leaving a 1.21 jar on a Java 17 image.
                $image = $this->versions()->ensureJavaImage($server, $gameVersion);

                if (! $image['ok']) {
                    Notification::make()
                        ->title('Cannot switch to this version')
                        ->body($image['message'])
                        ->danger()
                        ->send();

                    return;
                }

                if ($image['message'] && ! user()?->can(SubuserPermission::StartupDockerImage, $server)) {
                    Notification::make()
                        ->title('A different Java version is needed')
                        ->body($image['message'] . ' You do not have permission to change the docker image.')
                        ->danger()
                        ->send();

                    return;
                }

                $provider = $this->versions()->providerFor($this->profile());

                $result = $this->versions()->swapJar(
                    server: $server,
                    profile: $this->profile(),
                    provider: $provider,
                    gameVersion: $gameVersion,
                    buildId: $build,
                    archiveFirst: (bool) ($state['archive_first'] ?? true)
                        && user()?->can(SubuserPermission::FileArchive, $server),
                );

                Notification::make()
                    ->title($result['ok'] ? 'Version installed' : 'Version change failed')
                    ->body(trim($result['message'] . ' ' . ($image['message'] ?? '')))
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }

    private function reinstallAction(): Action
    {
        return Action::make('reinstall')
            ->label('Change version and reinstall')
            ->icon('tabler-refresh')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reinstall the server')
            ->modalDescription('This re-runs the egg\'s install script. Worlds and the mods directory are usually preserved — but that is up to the egg\'s script, and it is not guaranteed. Take a backup you can live with before continuing.')
            ->modalSubmitActionLabel('Reinstall')
            ->schema([
                Checkbox::make('i_have_a_backup')
                    ->label('I have a backup, or I accept losing this server\'s files')
                    ->accepted()
                    ->required(),
            ])
            ->action(function () {
                $server = $this->server();

                abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);
                abort_unless(user()?->can(SubuserPermission::SettingsReinstall, $server), 403);

                $state = $this->form->getState();
                $gameVersion = (string) ($state['game_version'] ?? '');

                if ($gameVersion === '') {
                    Notification::make()->title('Choose a version')->warning()->send();

                    return;
                }

                $image = $this->versions()->ensureJavaImage($server, $gameVersion);

                if (! $image['ok']) {
                    Notification::make()
                        ->title('Cannot switch to this version')
                        ->body($image['message'])
                        ->danger()
                        ->send();

                    return;
                }

                $written = $this->versions()->writeVersionVariables($server, $this->profile(), $gameVersion);

                if ($written === []) {
                    Notification::make()
                        ->title('Could not set the version')
                        ->body('This egg exposes no editable Minecraft version variable, or it rejected that value.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    app(ReinstallServerService::class)->handle($server);
                } catch (Throwable $exception) {
                    report($exception);

                    // Deliberately no status write here. ReinstallServerService
                    // wraps the state change and the daemon call in one
                    // transaction, so a throw has already rolled `Installing`
                    // back; stamping a failed state would strand the server in
                    // a conflict state it never actually entered. This mirrors
                    // the panel's own Settings page exactly.
                    Notification::make()
                        ->title('Reinstall could not be started')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Activity::event('server:settings.reinstall')->log();

                Activity::event('server:minecraft.version-change')
                    ->property(['mode' => 'reinstall', 'version' => $gameVersion])
                    ->log();

                Notification::make()
                    ->title('Reinstalling')
                    ->body('Watch the console for the install log.')
                    ->success()
                    ->send();

                // Land the user where the progress actually is.
                $this->redirect(Console::getUrl());
            });
    }
}
