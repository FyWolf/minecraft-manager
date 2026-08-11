<?php

return [
    'nav' => [
        'group' => 'Minecraft',
        'worlds' => 'Worlds',
        'configs' => 'Configuration',
        'versions' => 'Version',
        'content' => [
            'mods' => 'Mods',
            'plugins' => 'Plugins',
            'both' => 'Mods & Plugins',
        ],
        'modpacks' => 'Modpacks',
        'addons' => 'Addons',
    ],

    'profile' => [
        'source' => [
            'explicit' => 'Configured by an administrator.',
            'inherited' => 'Inherited from the parent egg :egg.',
            'heuristic' => 'Detected automatically from this egg (:loader). An administrator can pin it.',
        ],
    ],

    'server_running' => [
        'blocked' => 'The server must be stopped first.',
        'warning' => 'The server is running. Minecraft rewrites its files when it stops, so changes made now may be overwritten.',
    ],

    'permission_denied' => 'You do not have permission to do that.',

    'daemon_unreachable' => 'Could not reach the server daemon: :error',
];
