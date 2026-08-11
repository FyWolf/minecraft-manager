<?php

namespace FyWolf\MinecraftManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
 * Two rules govern this page.
 *
 * Nothing is ever dropped. Keys the schema does not know about — a future
 * Minecraft release, a fork's extra setting, a typo someone made by hand —
 * appear in a collapsed "Other settings" section as plain text inputs. They are
 * visible and editable, and they round-trip byte-for-byte if untouched, because
 * PropertiesFile rewrites individual lines rather than rebuilding the file.
 *
 * Nothing is ever logged that shouldn't be. `rcon.password` lives in this file,
 * so the activity entry records which keys changed and never their values.
 */
class ConfigsPage extends Page implements HasForms
{
    use BlockAccessInConflict;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-adjustments';

    protected static ?string $slug = 'mc-config';

    protected static ?int $navigationSort = 22;

    /** @var array<string, mixed> */
    public array $data = [];

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

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->server()), 403);

        $this->loadProperties();
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

    private function isRunning(): bool
    {
        return ! $this->server()->retrieveStatus()->isOffline();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemaDefinition(): array
    {
        return (array) config('minecraft-manager.configs.properties_schema', []);
    }

    private function loadProperties(): void
    {
        $properties = app(WorldService::class)->readProperties($this->server());

        if (! $properties) {
            $this->fileMissing = true;
            $this->data = [];
            $this->unknown = [];

            return;
        }

        $this->propertiesMemo = $properties;
        $this->fileMissing = false;

        $all = $properties->all();
        $schema = $this->schemaDefinition();

        $data = [];

        foreach ($schema as $key => $definition) {
            $raw = $all[$key] ?? null;

            $data[$this->fieldName($key)] = match ($definition['type'] ?? 'string') {
                'bool' => $raw === null
                    ? (bool) ($definition['default'] ?? false)
                    : in_array(strtolower($raw), ['true', '1', 'yes', 'on'], true),
                'int' => $raw === null || $raw === '' ? ($definition['default'] ?? null) : (int) $raw,
                default => $raw ?? (string) ($definition['default'] ?? ''),
            };
        }

        // A password is never echoed back into the browser. A blank submission
        // therefore has to mean "unchanged" — see save().
        foreach ($schema as $key => $definition) {
            if (! empty($definition['sensitive'])) {
                $data[$this->fieldName($key)] = null;
            }
        }

        $this->data = $data;
        $this->unknown = array_diff_key($all, $schema);

        $this->form->fill($data);
    }

    /**
     * Property keys contain dots (`rcon.port`), which Livewire would read as
     * nested array paths, so they are flattened for the form and restored on
     * save.
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

        $sections = [];

        foreach ($groups as $group => $label) {
            $fields = [];

            foreach ($definition as $key => $spec) {
                if (($spec['group'] ?? null) !== $group) {
                    continue;
                }

                $fields[] = $this->buildField($key, $spec);
            }

            if ($fields !== []) {
                $sections[] = Section::make($label)->columns(2)->schema($fields)->collapsible();
            }
        }

        // Everything the schema does not describe. Rendered, not discarded.
        if ($this->unknown !== []) {
            $sections[] = Section::make('Other settings')
                ->description('Present in the file but not described by this plugin — a newer Minecraft release, or a fork\'s own setting. Edited here as plain text and otherwise left exactly as found.')
                ->collapsed()
                ->columns(2)
                ->schema(array_map(
                    fn (string $key) => TextInput::make('unknown.' . $this->fieldName($key))->label($key),
                    array_keys($this->unknown),
                ));
        }

        return $schema->components($sections)->statePath('data');
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function buildField(string $key, array $spec)
    {
        $name = $this->fieldName($key);
        $managed = ! empty($spec['managed_by_panel']);

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

        if ($managed) {
            // The panel assigns allocations; letting a player edit the port here
            // produces a server that binds somewhere the panel will not route to.
            $field = $field
                ->disabled()
                ->helperText('Managed by the panel — change it through the server\'s allocation.');
        }

        if (! empty($spec['helper']) && ! $managed) {
            $field = $field->helperText($spec['helper']);
        }

        return $field;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('tabler-device-floppy')
                ->keyBindings(['mod+s'])
                ->requiresConfirmation(fn () => $this->isRunning())
                ->modalHeading('The server is running')
                ->modalDescription(trans('minecraft-manager::strings.server_running.warning'))
                ->modalSubmitActionLabel('Save anyway')
                ->action(fn (DaemonFileRepository $files) => $this->save($files))
                ->disabled(fn () => $this->fileMissing),

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

    private function save(DaemonFileRepository $files): void
    {
        $server = $this->server();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);

        $properties = $this->propertiesMemo ?? app(WorldService::class)->readProperties($server);

        if (! $properties) {
            Notification::make()->title('Could not read server.properties')->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $definition = $this->schemaDefinition();

        $candidate = [];

        foreach ($definition as $key => $spec) {
            if (! empty($spec['managed_by_panel'])) {
                continue;
            }

            $value = $state[$this->fieldName($key)] ?? null;

            // Blank on a write-only field means "leave it alone", exactly as the
            // plugin's own CurseForge key behaves.
            if (! empty($spec['sensitive']) && blank($value)) {
                continue;
            }

            $candidate[$key] = match ($spec['type'] ?? 'string') {
                'bool' => $value ? 'true' : 'false',
                'int' => (string) (int) $value,
                default => (string) $value,
            };
        }

        foreach ((array) ($state['unknown'] ?? []) as $field => $value) {
            $candidate[$this->keyFromField($field)] = (string) $value;
        }

        $changed = $properties->changedKeys($candidate);

        if ($changed === []) {
            Notification::make()->title('Nothing to save')->body('No values changed.')->send();

            return;
        }

        try {
            $files->setServer($server)->putContent('server.properties', $properties->merge($candidate)->render());

            Activity::event('server:minecraft.config-edit')
                ->property([
                    'file' => 'server.properties',
                    // Names only. This file contains rcon.password.
                    'changed' => implode(', ', $changed),
                    'changed_keys' => $changed,
                ])
                ->log();

            $this->propertiesMemo = null;
            $this->loadProperties();

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

    private function eulaNeedsAccepting(): bool
    {
        try {
            $contents = app(DaemonFileRepository::class)->setServer($this->server())->getContent('eula.txt', 4096);
        } catch (Throwable) {
            // No eula.txt at all: the server has never run, so there is nothing
            // to accept yet and the button stays hidden.
            return false;
        }

        return ! preg_match('/^\s*eula\s*=\s*true\s*$/mi', $contents);
    }

    private function acceptEula(DaemonFileRepository $files): void
    {
        $server = $this->server();

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
