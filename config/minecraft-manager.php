<?php

/**
 * Minecraft Manager.
 *
 * Loaded by the panel as `config('minecraft-manager.*')` — the filename must
 * stay equal to the plugin id (PluginService reads
 * `plugin_path($id, 'config', $id . '.php')`).
 *
 * Secrets come from the environment and are written there by the plugin's
 * settings slide-over, never edited here.
 */
return [
    /*
    |---------------------------------------------------------------------------
    | Content providers
    |---------------------------------------------------------------------------
    */

    'modrinth' => [
        'base_url' => env('MCM_MODRINTH_URL', 'https://api.modrinth.com/v2'),
    ],

    'curseforge' => [
        'base_url' => env('MCM_CURSEFORGE_URL', 'https://api.curseforge.com/v1'),

        // No key => CurseForgeProvider::isAvailable() is false => the registry
        // drops it and no CurseForge UI is rendered anywhere.
        'api_key' => env('CURSEFORGE_API_KEY', ''),

        // Minecraft. Overridable because CurseForge does not document these as
        // stable constants; the provider discovers class ids at runtime and
        // falls back to the values below.
        'game_id' => (int) env('MCM_CURSEFORGE_GAME_ID', 432),

        'class_ids' => [
            'mod' => 6,
            'plugin' => 5,
            'modpack' => 4471,
            'resourcepack' => 12,
            'world' => 17,
        ],
    ],

    'http' => [
        'timeout' => (int) env('MCM_HTTP_TIMEOUT', 8),
        'connect_timeout' => (int) env('MCM_HTTP_CONNECT_TIMEOUT', 4),
        'retries' => (int) env('MCM_HTTP_RETRIES', 2),
    ],

    'cache' => [
        'search' => 900,        // 15 min
        'versions' => 1800,     // 30 min
        'immutable' => 86400,   // 24 h — anything addressed by an immutable id
        'unavailable' => 60,    // negative cache so a dead API isn't re-probed per request
        'directory' => 30,      // daemon directory listings
    ],

    /*
    |---------------------------------------------------------------------------
    | Capability resolution
    |---------------------------------------------------------------------------
    |
    | Resolution order is: explicit egg mapping -> the parent egg's mapping
    | (config_from) -> heuristic -> null. A null result hides every page; an
    | unknown custom egg is invisible rather than half-broken.
    |
    */

    'heuristics' => [
        'enabled' => (bool) env('MCM_HEURISTICS', true),

        // An egg only qualifies as Minecraft-Java if it carries the `minecraft`
        // tag OR one of the panel's real registered feature ids. Note these are
        // the ONLY Minecraft-ish ids the panel actually registers — `mods` and
        // `plugins` are not among them, which is why gating on those (as
        // minecraft-modrinth does) never matches on a stock panel.
        'features' => ['eula', 'java_version'],

        // Ordered most-specific first. Order is load-bearing:
        //  - `neoforge` must precede `forge` (substring trap)
        //  - `purpur`/`folia` must precede `paper` (forks carry both tags)
        'loader_tokens' => [
            'neoforge' => ['neoforge', 'neoforged'],
            'forge' => ['forge'],
            'quilt' => ['quilt'],
            'fabric' => ['fabric'],
            'purpur' => ['purpur'],
            'folia' => ['folia'],
            'paper' => ['paper', 'spigot', 'bukkit'],
            'velocity' => ['velocity'],
            'bungeecord' => ['bungeecord', 'waterfall'],
            'vanilla' => ['vanilla'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Built-in capability profiles
    |---------------------------------------------------------------------------
    |
    | Seeded into `mc_capability_profiles` on install, and used directly (without
    | a DB row) when the heuristic resolves an egg that has no explicit mapping.
    | Heuristic results are deliberately never persisted — writing rows behind
    | the admin's back would fight them the next time they edit one.
    |
    | capabilities:    worlds, configs, versions, mods, plugins, modpacks,
    |                  resourcepacks, datapacks
    | content_dir:     where mods/plugins are installed; null = no content browser
    | dimension_layout: `bukkit`  => world_nether / world_the_end are SIBLINGS
    |                   `vanilla` => dimensions live inside world/DIM-1
    | version_provider: null => this loader ships an installer, not a runnable
    |                   jar, so version changes take the reinstall path
    |
    */

    'profiles' => [
        'vanilla' => [
            'name' => 'Vanilla',
            'loader' => 'vanilla',
            'capabilities' => ['worlds', 'configs', 'versions', 'datapacks'],
            'content_dir' => null,
            'worlds_dir' => '/',
            'dimension_layout' => 'vanilla',
            'version_provider' => 'vanilla',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION', 'VERSION'],
            'loader_version_variables' => [],
        ],

        'paper' => [
            'name' => 'Paper / Bukkit',
            'loader' => 'paper',
            'capabilities' => ['worlds', 'configs', 'versions', 'plugins', 'datapacks'],
            'content_dir' => 'plugins',
            'worlds_dir' => '/',
            'dimension_layout' => 'bukkit',
            'version_provider' => 'paper',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['BUILD_NUMBER'],
        ],

        'purpur' => [
            'name' => 'Purpur',
            'loader' => 'purpur',
            'capabilities' => ['worlds', 'configs', 'versions', 'plugins', 'datapacks'],
            'content_dir' => 'plugins',
            'worlds_dir' => '/',
            'dimension_layout' => 'bukkit',
            'version_provider' => 'purpur',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['BUILD_NUMBER'],
        ],

        'folia' => [
            'name' => 'Folia',
            'loader' => 'folia',
            'capabilities' => ['worlds', 'configs', 'versions', 'plugins'],
            'content_dir' => 'plugins',
            'worlds_dir' => '/',
            'dimension_layout' => 'bukkit',
            'version_provider' => 'folia',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['BUILD_NUMBER'],
        ],

        'fabric' => [
            'name' => 'Fabric',
            'loader' => 'fabric',
            'capabilities' => ['worlds', 'configs', 'versions', 'mods', 'modpacks', 'resourcepacks', 'datapacks'],
            'content_dir' => 'mods',
            'worlds_dir' => '/',
            'dimension_layout' => 'vanilla',
            'version_provider' => 'fabric',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['FABRIC_VERSION', 'LOADER_VERSION'],
        ],

        'quilt' => [
            'name' => 'Quilt',
            'loader' => 'quilt',
            'capabilities' => ['worlds', 'configs', 'versions', 'mods', 'modpacks', 'resourcepacks', 'datapacks'],
            'content_dir' => 'mods',
            'worlds_dir' => '/',
            'dimension_layout' => 'vanilla',
            'version_provider' => null,
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['QUILT_VERSION', 'LOADER_VERSION'],
        ],

        'forge' => [
            'name' => 'Forge',
            'loader' => 'forge',
            // version_provider is null on purpose: Forge distributes an
            // *installer* jar, not a runnable server jar, so a jar swap would
            // produce a server that cannot boot. Version changes go through
            // variable + reinstall and let the egg's install script do it.
            'capabilities' => ['worlds', 'configs', 'versions', 'mods', 'modpacks', 'resourcepacks'],
            'content_dir' => 'mods',
            'worlds_dir' => '/',
            'dimension_layout' => 'vanilla',
            'version_provider' => null,
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['FORGE_VERSION', 'BUILD_TYPE'],
        ],

        'neoforge' => [
            'name' => 'NeoForge',
            'loader' => 'neoforge',
            'capabilities' => ['worlds', 'configs', 'versions', 'mods', 'modpacks', 'resourcepacks'],
            'content_dir' => 'mods',
            'worlds_dir' => '/',
            'dimension_layout' => 'vanilla',
            'version_provider' => null,
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['NEOFORGE_VERSION'],
        ],

        'velocity' => [
            'name' => 'Velocity (proxy)',
            'loader' => 'velocity',
            // A proxy has no worlds and no server.properties.
            'capabilities' => ['configs', 'versions', 'plugins'],
            'content_dir' => 'plugins',
            'worlds_dir' => null,
            'dimension_layout' => 'vanilla',
            'version_provider' => 'velocity',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['BUILD_NUMBER'],
        ],

        'bungeecord' => [
            'name' => 'BungeeCord / Waterfall (proxy)',
            'loader' => 'bungeecord',
            'capabilities' => ['configs', 'versions', 'plugins'],
            'content_dir' => 'plugins',
            'worlds_dir' => null,
            'dimension_layout' => 'vanilla',
            'version_provider' => 'waterfall',
            'jar_path' => null,
            'mc_version_variables' => ['MINECRAFT_VERSION', 'MC_VERSION'],
            'loader_version_variables' => ['BUILD_NUMBER'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Worlds
    |---------------------------------------------------------------------------
    */

    'worlds' => [
        // Never probed as world candidates. Saves a getDirectory() round-trip
        // each and stops `plugins` being offered for deletion as a "world".
        'ignored_directories' => [
            'plugins', 'mods', 'config', 'libraries', 'cache', 'logs', 'crash-reports',
            'versions', 'defaultconfigs', 'kubejs', 'scripts', 'resourcepacks', 'datapacks',
            'backups', 'server-icon', '.git', '.cache', 'shaderpacks',
        ],

        // Bukkit-family dimension suffixes. `world` + `world_nether` +
        // `world_the_end` are one logical world in three folders; archiving or
        // deleting the base without its siblings is how world managers corrupt
        // servers.
        'dimension_suffixes' => ['_nether', '_the_end'],

        // Subdirectories walked to approximate a world's size, since Wings
        // reports the inode size for a directory rather than a recursive total.
        'size_probe_paths' => ['region', 'entities', 'poi', 'DIM-1/region', 'DIM1/region'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Configs
    |---------------------------------------------------------------------------
    |
    | Typed presentation for server.properties. Keys present in the file but
    | absent here are NOT dropped — they render in a collapsed "Other settings"
    | section as plain text inputs. Keys here but absent from the file show the
    | Minecraft default and are only written if the user changes them.
    |
    */

    'configs' => [
        'max_file_size' => 512 * 1024,

        'properties_schema' => [
            // World
            'level-name' => ['type' => 'string', 'group' => 'world', 'default' => 'world'],
            'level-seed' => ['type' => 'string', 'group' => 'world', 'default' => ''],
            'level-type' => ['type' => 'enum', 'group' => 'world', 'default' => 'minecraft:normal',
                'options' => ['minecraft:normal', 'minecraft:flat', 'minecraft:large_biomes', 'minecraft:amplified', 'minecraft:single_biome_surface']],
            'gamemode' => ['type' => 'enum', 'group' => 'world', 'default' => 'survival',
                'options' => ['survival', 'creative', 'adventure', 'spectator']],
            'difficulty' => ['type' => 'enum', 'group' => 'world', 'default' => 'easy',
                'options' => ['peaceful', 'easy', 'normal', 'hard']],
            'hardcore' => ['type' => 'bool', 'group' => 'world', 'default' => false],
            'allow-nether' => ['type' => 'bool', 'group' => 'world', 'default' => true],
            'generate-structures' => ['type' => 'bool', 'group' => 'world', 'default' => true],
            'spawn-monsters' => ['type' => 'bool', 'group' => 'world', 'default' => true],
            'spawn-animals' => ['type' => 'bool', 'group' => 'world', 'default' => true],
            'spawn-npcs' => ['type' => 'bool', 'group' => 'world', 'default' => true],
            'max-world-size' => ['type' => 'int', 'group' => 'world', 'default' => 29999984, 'min' => 1, 'max' => 29999984],

            // Players
            'max-players' => ['type' => 'int', 'group' => 'players', 'default' => 20, 'min' => 0, 'max' => 2147483647],
            'motd' => ['type' => 'string', 'group' => 'players', 'default' => 'A Minecraft Server'],
            'pvp' => ['type' => 'bool', 'group' => 'players', 'default' => true],
            'force-gamemode' => ['type' => 'bool', 'group' => 'players', 'default' => false],
            'player-idle-timeout' => ['type' => 'int', 'group' => 'players', 'default' => 0, 'min' => 0],
            'allow-flight' => ['type' => 'bool', 'group' => 'players', 'default' => false],
            'spawn-protection' => ['type' => 'int', 'group' => 'players', 'default' => 16, 'min' => 0],

            // Security
            'online-mode' => ['type' => 'bool', 'group' => 'security', 'default' => true],
            'white-list' => ['type' => 'bool', 'group' => 'security', 'default' => false],
            'enforce-whitelist' => ['type' => 'bool', 'group' => 'security', 'default' => false],
            'enforce-secure-profile' => ['type' => 'bool', 'group' => 'security', 'default' => true],
            'prevent-proxy-connections' => ['type' => 'bool', 'group' => 'security', 'default' => false],
            'hide-online-players' => ['type' => 'bool', 'group' => 'security', 'default' => false],

            // Performance
            'view-distance' => ['type' => 'int', 'group' => 'performance', 'default' => 10, 'min' => 2, 'max' => 32],
            'simulation-distance' => ['type' => 'int', 'group' => 'performance', 'default' => 10, 'min' => 2, 'max' => 32],
            'max-tick-time' => ['type' => 'int', 'group' => 'performance', 'default' => 60000, 'min' => -1],
            'entity-broadcast-range-percentage' => ['type' => 'int', 'group' => 'performance', 'default' => 100, 'min' => 10, 'max' => 1000],
            'sync-chunk-writes' => ['type' => 'bool', 'group' => 'performance', 'default' => true],
            'network-compression-threshold' => ['type' => 'int', 'group' => 'performance', 'default' => 256, 'min' => -1],

            // Network — deliberately read-only-ish; the panel owns the port.
            'server-port' => ['type' => 'int', 'group' => 'network', 'default' => 25565, 'min' => 1, 'max' => 65535, 'managed_by_panel' => true],
            'server-ip' => ['type' => 'string', 'group' => 'network', 'default' => '', 'managed_by_panel' => true],
            'enable-query' => ['type' => 'bool', 'group' => 'network', 'default' => false],
            'query.port' => ['type' => 'int', 'group' => 'network', 'default' => 25565, 'min' => 1, 'max' => 65535],
            'enable-status' => ['type' => 'bool', 'group' => 'network', 'default' => true],

            // RCON
            'enable-rcon' => ['type' => 'bool', 'group' => 'rcon', 'default' => false],
            'rcon.port' => ['type' => 'int', 'group' => 'rcon', 'default' => 25575, 'min' => 1, 'max' => 65535],
            'rcon.password' => ['type' => 'string', 'group' => 'rcon', 'default' => '', 'sensitive' => true],
            'broadcast-rcon-to-ops' => ['type' => 'bool', 'group' => 'rcon', 'default' => true],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Versions
    |---------------------------------------------------------------------------
    */

    'versions' => [
        // Base urls kept in config because upstreams move. PaperMC in
        // particular has announced a v3 ("fill") API; if v2 is retired only
        // this line needs to change.
        'paper_url' => env('MCM_PAPER_URL', 'https://api.papermc.io/v2'),
        'purpur_url' => env('MCM_PURPUR_URL', 'https://api.purpurmc.org/v2'),
        'fabric_url' => env('MCM_FABRIC_URL', 'https://meta.fabricmc.net/v2'),
        'vanilla_manifest' => env('MCM_VANILLA_MANIFEST', 'https://launchermeta.mojang.com/mc/game/version_manifest_v2.json'),

        'default_jar' => 'server.jar',

        // Minimum Java major per Minecraft version, highest floor first.
        // Used to move the server's docker image when a version bump needs it.
        'java_requirements' => [
            ['min_mc' => '1.20.5', 'java' => 21],
            ['min_mc' => '1.18', 'java' => 17],
            ['min_mc' => '1.17', 'java' => 16],
            ['min_mc' => '1.0', 'java' => 8],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Modpacks
    |---------------------------------------------------------------------------
    */

    'packs' => [
        // Give this its own worker (`queue:work --queue=mcm-packs`). A 20-minute
        // pack install on the default queue blocks every other job the panel
        // has — backups, webhooks, SFTP revocation.
        'queue' => env('MCM_PACKS_QUEUE', 'default'),

        'max_archive_size' => (int) env('MCM_PACKS_MAX_SIZE', 512 * 1024 * 1024),
        'max_files' => (int) env('MCM_PACKS_MAX_FILES', 1000),

        // The daemon pull rate limit (5/10min) is route middleware and does not
        // apply to a queued job, so we self-throttle rather than hammer a node
        // harder than the panel's own UI ever could.
        'file_delay_ms' => (int) env('MCM_PACKS_FILE_DELAY_MS', 150),
        'file_retries' => 2,

        // Abort the whole install once this share of files has failed.
        'failure_threshold' => 0.2,
        'consecutive_connection_failures' => 5,

        // A row left non-terminal for longer than this is treated as abandoned
        // (worker restarted mid-install) and failed, so the concurrency guard
        // does not lock that server out permanently.
        'stale_after_minutes' => (int) env('MCM_PACKS_STALE_MINUTES', 30),

        // Never overwritten from a pack's overrides/ directory — doing so
        // silently resets the port, level-name and MOTD.
        'protected_overrides' => ['server.properties', 'eula.txt'],
    ],
];
