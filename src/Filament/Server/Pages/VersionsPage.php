<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Pages\Console;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Services\Servers\ReinstallServerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Integrations\Versions\VanillaProvider;
use FyWolf\MinecraftManager\Services\ContentInstallService;
use FyWolf\MinecraftManager\Services\VersionInstallService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Throwable;

/**
 * Change the server's Minecraft version.
 *
 * Extends ServerFormPage, which is what supplies `$this->form`. A plain
 * Filament Page has no form property at all — referencing it throws
 * "Property [$form] not found on component".
 *
 * Which mechanism is offered comes from the capability profile:
 *
 *  - A profile with a version provider gets a jar swap. Nothing but the jar is
 *    touched, so worlds, mods and configuration are safe by construction.
 *  - A profile without one gets variable + reinstall, because its loader ships
 *    an installer rather than a runnable jar and only the egg's install script
 *    knows what to do with it.
 *
 * A provider that is merely *unreachable* also falls back to reinstall, with a
 * banner — degrade toward the thing that always works rather than render an
 * empty version list that reads as broken.
 */
class VersionsPage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-versions';

    protected static string|\UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?string $slug = 'mc-version';

    protected static ?int $navigationSort = 24;

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

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupRead, $this->getRecord()), 403);
    }

    /**
     * Seed the form with the current version.
     *
     * Overrides the parent, which fills the form from the Server model's own
     * attributes — none of these fields are columns.
     */
    protected function fillForm(): void
    {
        $this->form->fill([
            'game_version' => $this->currentVersion(),
            'archive_first' => true,
        ]);
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->getRecord());
    }

    private function versions(): VersionInstallService
    {
        return app(VersionInstallService::class);
    }

    private function currentVersion(): ?string
    {
        return app(ContentInstallService::class)->minecraftVersion($this->getRecord(), $this->profile());
    }

    private function isRunning(): bool
    {
        return ! $this->getRecord()->retrieveStatus()->isOffline();
    }

    /**
     * Whether the jar-swap path is usable right now.
     *
     * Both halves matter: a profile with no provider never swaps, and a
     * provider whose upstream is down would otherwise render an empty select
     * that looks like the plugin is broken.
     */
    private function canSwapJar(): bool
    {
        $provider = $this->versions()->providerFor($this->profile());

        return $provider !== null && $provider->gameVersions() !== [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->statusSection(),
                $this->canSwapJar() ? $this->jarSection() : $this->reinstallSection(),
            ])
            ->statePath('data');
    }

    private function statusSection(): Section
    {
        return Section::make('This server')
            ->columns(4)
            ->schema([
                Placeholder::make('current_version')
                    ->label('Current version')
                    ->content(fn () => $this->currentVersion() ?? 'not set'),

                Placeholder::make('software')
                    ->label('Software')
                    ->content(fn () => $this->profile()->loader?->getLabel() ?? 'unknown'),

                Placeholder::make('method')
                    ->label('Method')
                    ->content(fn () => $this->canSwapJar() ? 'Replace the jar' : 'Reinstall'),

                Placeholder::make('power')
                    ->label('Server')
                    ->content(fn () => $this->isRunning() ? 'Running — stop it first' : 'Stopped'),
            ]);
    }

    private function jarSection(): Section
    {
        $provider = $this->versions()->providerFor($this->profile());

        return Section::make('Change version')
            ->description('Downloads the chosen build over the existing server jar. Worlds, mods and configuration are not touched.')
            ->columns(2)
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
                    ->helperText('About 50 MB, and makes rolling back instant.')
                    ->columnSpanFull(),
            ])
            ->footerActions([$this->swapAction()]);
    }

    private function reinstallSection(): Section
    {
        $configured = filled($this->profile()->versionProvider);

        return Section::make('Change version')
            ->description($configured
                ? 'The version service for this software is unreachable, so the jar cannot be downloaded directly. Changing the version will re-run the egg\'s install script instead.'
                : 'This software is distributed as an installer rather than a ready-to-run jar, so the version is changed by updating the startup variable and reinstalling.')
            ->schema([
                Select::make('game_version')
                    ->label('Minecraft version')
                    ->options(fn () => $this->reinstallVersionOptions())
                    ->searchable()
                    ->required()
                    ->helperText('Must be a version this egg\'s install script understands.'),
            ])
            ->footerActions([$this->reinstallAction()]);
    }

    /**
     * Offer the egg's own allowed values when its variable rules constrain
     * them; otherwise fall back to the Vanilla release manifest.
     *
     * @return array<string, string>
     */
    private function reinstallVersionOptions(): array
    {
        $candidates = array_map('strtoupper', $this->profile()->mcVersionVariables ?: ['MINECRAFT_VERSION']);

        foreach ($this->getRecord()->variables as $variable) {
            if (! in_array(strtoupper((string) $variable->env_variable), $candidates, true)) {
                continue;
            }

            foreach ((array) ($variable->rules ?? []) as $rule) {
                if (is_string($rule) && str_starts_with($rule, 'in:')) {
                    $values = array_filter(explode(',', substr($rule, 3)));

                    if ($values !== []) {
                        return array_combine($values, $values);
                    }
                }
            }
        }

        $vanilla = app(VanillaProvider::class)->gameVersions();

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
                $server = $this->getRecord();

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

                // Check the image BEFORE downloading: refusing early beats
                // leaving a 1.21 jar on a Java 17 image.
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

                $result = $this->versions()->swapJar(
                    server: $server,
                    profile: $this->profile(),
                    provider: $this->versions()->providerFor($this->profile()),
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

                $this->fillForm();
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
                $server = $this->getRecord();

                abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);
                abort_unless(user()?->can(SubuserPermission::SettingsReinstall, $server), 403);

                $gameVersion = (string) ($this->form->getState()['game_version'] ?? '');

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

                    // Deliberately no status write. ReinstallServerService wraps
                    // the state change and the daemon call in one transaction,
                    // so a throw has already rolled 'Installing' back; stamping
                    // a failed state would strand the server in a conflict
                    // state it never entered. Mirrors the panel's own Settings
                    // page.
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

                $this->redirect(Console::getUrl());
            });
    }

    /**
     * The page has no plain "save" — both paths are explicit, confirmed
     * actions, so the form's own submit button would be meaningless.
     *
     * Public, not protected: InteractsWithFormActions declares this public and
     * PHP forbids narrowing inherited visibility, fatally, at class-load time.
     *
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [];
    }

    public function save(): void
    {
        // Intentionally empty: see getFormActions().
    }
}
