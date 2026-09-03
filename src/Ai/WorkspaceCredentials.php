<?php

namespace Packstub\Agents\Ai;

/**
 * A workspace that brought its own provider: which one, the key, and the
 * picker entry it prefers by default. Returned by the credentials callback
 * registered on AgentsPlugin; null there means "the platform's provider".
 */
final class WorkspaceCredentials
{
    public function __construct(
        public readonly ?string $provider = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $model = null,
    ) {}
}
