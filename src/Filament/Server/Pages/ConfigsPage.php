<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Pages\ServerFormPage;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use FyWolf\MinecraftManager\Enums\Capability;
use FyWolf\MinecraftManager\Services\WorldService;
use FyWolf\MinecraftManager\Support\CapabilityResolver;
use FyWolf\MinecraftManager\Support\PropertiesFile;
use FyWolf\MinecraftManager\Support\ResolvedProfile;
use Throwable;

/**
 * Edit server.properties as a form.
 *
 * Extends the panel's own ServerFormPage rather than a plain Page. That base
 * class is what supplies `$this->form` (via InteractsWithForms), the `$data`
 * state path, and the Blade view that actually renders and submits a form —
 * a plain Page has `content()` and no form at all, which renders an empty page.
 *
 * Two rules govern what this does to the file.
 *
 * Nothing is ever dropped. Keys the schema does not know about — a future
 * Minecraft release, a fork's extra setting, a hand-written typo — appear in a
 * collapsed "Other settings" section as plain text inputs. They are visible and
 * editable, and they round-trip byte-for-byte if untouched, because
 * PropertiesFile rewrites individual lines rather than rebuilding the file.
 *
 * Nothing is ever logged that shouldn't be. `rcon.password` lives in this file,
 * so the activity entry records which keys changed and never their values.
 */
class ConfigsPage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-adjustments';

    protected static string|\UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?string $slug = 'mc-config';

    protected static ?int $navigationSort = 22;

    /** @var array<string, string> */
    public array $unknown = [];

    public bool $fileMissing = false;

    private ?ResolvedProfile $profileMemo = null;

    private ?PropertiesFile $propertiesMemo = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (! $server instanceof Server) {
            return false;
        }

        $profile = app(CapabilityResolver::class)->for($server);

        return parent::canAccess()
            && $profile?->has(Capability::Configs)
            && user()?->can(SubuserPermission::FileReadContent, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-manager::strings.nav.configs');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->getRecord()), 403);

        // Filament runs mount() before it enforces canAccess(), so this page is
        // reachable by a server whose egg resolves to no profile. Fail as a 403
        // rather than letting a null profile surface as a TypeError later.
        $this->profileMemo = app(CapabilityResolver::class)->for($this->getRecord());

        abort_unless($this->profileMemo?->has(Capability::Configs), 403);
    }

    private function profile(): ResolvedProfile
    {
        return $this->profileMemo ??= app(CapabilityResolver::class)->for($this->getRecord());
    }

    private function isRunning(): bool
    {
        return ! $this->getRecord()->retrieveStatus()->isOffline();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemaDefinition(): array
    {
        return (array) config('minecraft-manager.configs.properties_schema', []);
    }

    /**
     * Read server.properties into the form.
     *
     * Overrides the parent, which fills the form with the Server model's own
     * attributes — meaningless here, since none of these fields are columns.
     */
    protected function fillForm(): void
    {
        $properties = app(WorldService::class)->readProperties($this->getRecord());

        if (! $properties) {
            $this->fileMissing = true;
            $this->unknown = [];
            $this->form->fill([]);

            return;
        }

        $this->propertiesMemo = $properties;
        $this->fileMissing = false;

        $all = $properties->all();
        $definition = $this->schemaDefinition();

        $data = [];

        foreach ($definition as $key => $spec) {
            $raw = $all[$key] ?? null;

            $data[$this->fieldName($key)] = match ($spec['type'] ?? 'string') {
                'bool' => $raw === null
                    ? (bool) ($spec['default'] ?? false)
                    : in_array(strtolower($raw), ['true', '1', 'yes', 'on'], true),
                'int' => $raw === null || $raw === '' ? ($spec['default'] ?? null) : (int) $raw,
                default => $raw ?? (string) ($spec['default'] ?? ''),
            };

            // A password is never echoed back to the browser, so a blank
            // submission has to mean "unchanged" — see save().
            if (! empty($spec['sensitive'])) {
                $data[$this->fieldName($key)] = null;
            }
        }

        $this->unknown = array_diff_key($all, $definition);

        foreach ($this->unknown as $key => $value) {
            $data['unknown'][$this->fieldName($key)] = $value;
        }

        $this->form->fill($data);
    }

    /**
     * Property keys contain dots (`rcon.port`), which Livewire reads as nested
     * array paths, so they are flattened for the form and restored on save.
     */
    private function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function keyFromField(string $field): string
    {
        return str_replace('__', '.', $field);
    }

    public function form(Schema $schema): Schema
    {
        $definition = $this->schemaDefinition();

        $groups = [
            'world' => 'World',
            'players' => 'Players',
            'security' => 'Security',
            'performance' => 'Performance',
            'network' => 'Network',
            'rcon' => 'RCON',
        ];

        $components = [];

        if ($this->fileMissing) {
            $components[] = Section::make('No server.properties yet')
                ->description('This server has not generated its configuration. Start it once, then come back.')
                ->schema([
                    Placeholder::make('missing_hint')
                        ->label('')
                        ->content('Minecraft writes server.properties on its first run.'),
                ]);

            return $schema->components($components)->statePath('data');
        }

        if ($this->isRunning()) {
            $components[] = Section::make('The server is running')
                ->description(trans('minecraft-manager::strings.server_running.warning'))
                ->schema([]);
        }

        foreach ($groups as $group => $label) {
            $fields = [];

            foreach ($definition as $key => $spec) {
                if (($spec['group'] ?? null) === $group) {
                    $fields[] = $this->buildField($key, $spec);
                }
            }

            if ($fields !== []) {
                $components[] = Section::make($label)->columns(2)->schema($fields)->collapsible();
            }
        }

        // Everything the schema does not describe. Rendered, not discarded.
        if ($this->unknown !== []) {
            $components[] = Section::make('Other settings')
                ->description('Present in the file but not described by this plugin — a newer Minecraft release, or a fork\'s own setting. Edited here as plain text and otherwise left exactly as found.')
                ->collapsed()
                ->columns(2)
                ->schema(array_map(
                    fn (string $key) => TextInput::make('unknown.' . $this->fieldName($key))->label($key),
                    array_keys($this->unknown),
                ));
        }

        return $schema
            ->components($components)
            ->statePath('data');
    }

    /**
     * @param array<string, mixed> $spec
     */
    /**
     * Whether a property is off-limits to the customer.
     *
     * Two sources: the per-key `managed_by_panel` flag (things the panel itself
     * owns, like the port), and the admin-configurable lock list (things
     * provisioning owns, like max-players on a host that sells slots).
     *
     * Consulted by BOTH the form and save(). The form use is a courtesy; the
     * save use is the actual enforcement.
     */
    private function isLocked(string $key, array $spec = []): bool
    {
        if (! empty($spec['managed_by_panel'])) {
            return true;
        }

        return in_array($key, (array) config('minecraft-manager.configs.locked_properties', []), true);
    }

    private function lockReason(string $key, array $spec = []): string
    {
        return ! empty($spec['managed_by_panel'])
            ? 'Managed by the panel — change it through the server\'s allocation.'
            : (string) config('minecraft-manager.configs.locked_reason', 'Locked by your host.');
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function buildField(string $key, array $spec)
    {
        $name = $this->fieldName($key);
        $locked = $this->isLocked($key, $spec);
        $canEdit = user()?->can(SubuserPermission::FileUpdate, $this->getRecord()) ?? false;

        $field = match ($spec['type'] ?? 'string') {
            'bool' => Toggle::make($name),

            'int' => TextInput::make($name)
                ->numeric()
                ->minValue($spec['min'] ?? null)
                ->maxValue($spec['max'] ?? null),

            'enum' => Select::make($name)
                ->options(array_combine($spec['options'] ?? [], $spec['options'] ?? []))
                ->selectablePlaceholder(false),

            default => TextInput::make($name),
        };

        $field = $field->label($key);

        if (! empty($spec['sensitive'])) {
            $field = $field
                ->password()
                ->revealable()
                ->autocomplete(false)
                ->placeholder('unchanged')
                ->helperText('Leave blank to keep the current value.');
        }

        if ($locked) {
            // Shown, not hidden: a greyed field with a reason is far less
            // confusing than a setting that has silently disappeared.
            $field = $field
                ->disabled()
                ->hintIcon('tabler-lock')
                ->helperText($this->lockReason($key, $spec));
        } elseif (! $canEdit) {
            $field = $field->disabled();
        }

        if (! empty($spec['helper']) && ! $locked) {
            $field = $field->helperText($spec['helper']);
        }

        return $field;
    }

    /**
     * The view submits to this (`wire:submit="save"`).
     */
    public function save(): void
    {
        $server = $this->getRecord();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

        $properties = $this->propertiesMemo ?? app(WorldService::class)->readProperties($server);

        if (! $properties) {
            Notification::make()->title('Could not read server.properties')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $definition = $this->schemaDefinition();

        $candidate = [];
        $rejected = [];

        $current = $properties->all();

        foreach ($definition as $key => $spec) {
            $value = $state[$this->fieldName($key)] ?? null;

            // Blank on a write-only field means "leave it alone", the same rule
            // the plugin's own CurseForge key follows.
            if (! empty($spec['sensitive']) && blank($value)) {
                continue;
            }

            $submitted = match ($spec['type'] ?? 'string') {
                'bool' => $value ? 'true' : 'false',
                'int' => (string) (int) $value,
                default => (string) $value,
            };

            // The real enforcement. Disabling the field in the form only stops
            // an honest browser — Livewire state arrives from the client and can
            // say anything, so a locked key must be dropped here as well.
            //
            // Only counted as an attempt when the submitted value actually
            // differs from what is on disk; otherwise every save of the page
            // would report a rejection for every locked field.
            if ($this->isLocked($key, $spec)) {
                if (array_key_exists($key, $current) && $current[$key] !== $submitted) {
                    $rejected[] = $key;
                }

                continue;
            }

            $candidate[$key] = $submitted;
        }

        foreach ((array) ($state['unknown'] ?? []) as $field => $value) {
            $key = $this->keyFromField($field);

            // A locked key that the schema does not describe still arrives
            // through the passthrough section, so it has to be filtered here
            // too — otherwise locking anything outside the typed schema would
            // do nothing at all.
            if ($this->isLocked($key)) {
                if (array_key_exists($key, $current) && $current[$key] !== (string) $value) {
                    $rejected[] = $key;
                }

                continue;
            }

            $candidate[$key] = (string) $value;
        }

        // A locked value arriving changed means the form state was edited past
        // the disabled attribute. Worth recording — it is the only signal a host
        // gets that someone is probing the limits of their plan.
        if ($rejected !== []) {
            Activity::event('server:minecraft.config-locked-rejected')
                ->property(['file' => 'server.properties', 'keys' => implode(', ', array_unique($rejected))])
                ->log();
        }

        $changed = $properties->changedKeys($candidate);

        if ($changed === []) {
            Notification::make()
                ->title('Nothing to save')
                ->body($rejected === []
                    ? 'No values changed.'
                    : implode(', ', array_unique($rejected)) . ' cannot be changed here. ' . $this->lockReason($rejected[0]))
                ->send();

            return;
        }

        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->putContent('server.properties', $properties->merge($candidate)->render());

            Activity::event('server:minecraft.config-edit')
                ->property([
                    'file' => 'server.properties',
                    // Names only. This file contains rcon.password.
                    'changed' => implode(', ', $changed),
                    'changed_keys' => $changed,
                ])
                ->log();

            $this->propertiesMemo = null;
            $this->fillForm();

            Notification::make()
                ->title('server.properties saved')
                ->body(count($changed) . ' setting(s) changed' . ($this->isRunning() ? ' — restart for them to take effect.' : '.'))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Could not write server.properties')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Public, not protected: InteractsWithFormActions declares this public, and
     * PHP forbids narrowing an inherited method's visibility. Doing so is a
     * fatal at class-load time, which in a panel means boot, which means the
     * whole panel — not just this page.
     *
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('tabler-device-floppy')
                ->submit('save')
                ->keyBindings(['mod+s'])
                ->disabled(fn () => $this->fileMissing
                    || ! user()?->can(SubuserPermission::FileUpdate, $this->getRecord())),
        ];
    }

    /**
     * getDefaultHeaderActions, not getHeaderActions.
     *
     * ServerFormPage carries CanCustomizeHeaderActions, whose getHeaderActions()
     * merges actions other plugins registered via registerCustomHeaderActions()
     * around this method. Overriding getHeaderActions() directly would compile
     * fine and silently discard every one of them.
     *
     * @return array<int, Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('eula')
                ->label('Accept the EULA')
                ->icon('tabler-file-check')
                ->color('gray')
                ->visible(fn () => $this->eulaNeedsAccepting())
                ->requiresConfirmation()
                ->modalHeading('Accept the Minecraft EULA')
                ->modalDescription('Writes eula=true to eula.txt. By doing so you agree to the Minecraft End User Licence Agreement at https://aka.ms/MinecraftEULA — the server will not start until this is accepted.')
                ->action(fn (DaemonFileRepository $files) => $this->acceptEula($files)),

            Action::make('open_files')
                ->label('File manager')
                ->icon('tabler-folder-open')
                ->color('gray')
                ->url(fn () => ListFiles::getUrl(['path' => '/']), true),
        ];
    }

    private function eulaNeedsAccepting(): bool
    {
        try {
            $contents = app(DaemonFileRepository::class)->setServer($this->getRecord())->getContent('eula.txt', 4096);
        } catch (Throwable) {
            // No eula.txt at all: the server has never run, so there is nothing
            // to accept yet and the button stays hidden.
            return false;
        }

        return ! preg_match('/^\s*eula\s*=\s*true\s*$/mi', $contents);
    }

    private function acceptEula(DaemonFileRepository $files): void
    {
        $server = $this->getRecord();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

        try {
            $existing = '';

            try {
                $existing = $files->setServer($server)->getContent('eula.txt', 4096);
            } catch (Throwable) {
                // Fine — we are about to create it.
            }

            $contents = $existing !== '' && preg_match('/^\s*eula\s*=/mi', $existing)
                ? preg_replace('/^\s*eula\s*=.*$/mi', 'eula=true', $existing)
                : rtrim($existing) . ($existing !== '' ? "\n" : '') . "eula=true\n";

            $files->setServer($server)->putContent('eula.txt', (string) $contents);

            Activity::event('server:minecraft.eula-accept')->log();

            Notification::make()->title('EULA accepted')->success()->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title('Could not write eula.txt')->body($exception->getMessage())->danger()->send();
        }
    }
}
