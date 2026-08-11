<?php

namespace FyWolf\MinecraftManager\Integrations\Content\Data;

/**
 * A relationship between one project version and another project.
 *
 * The existing minecraft-modrinth plugin ignores these entirely, which is why
 * installing a mod through it so often produces a server that will not boot:
 * most Fabric mods require Fabric API, and nothing tells the user.
 */
readonly class ContentDependency
{
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const INCOMPATIBLE = 'incompatible';

    public const EMBEDDED = 'embedded';

    public function __construct(
        public ?string $projectId,
        public ?string $versionId,
        public string $type,
        public ?string $name = null,
    ) {}

    public function isRequired(): bool
    {
        return $this->type === self::REQUIRED;
    }

    /**
     * Embedded dependencies are bundled inside the jar already, and installing
     * them separately duplicates classes on the classpath.
     */
    public function shouldInstall(): bool
    {
        return $this->type === self::REQUIRED && filled($this->projectId);
    }
}
