<?php

namespace Packstub\Agents;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Packstub\Agents\Filament\Resources\AgentAuditEntries\AgentAuditEntryResource;
use Packstub\Agents\Filament\Resources\AgentTokens\AgentTokenResource;
use Packstub\Agents\Filament\Resources\PendingApprovals\PendingApprovalResource;

class AgentsPlugin implements Plugin
{
    public static function make(): static
    {
        return new static;
    }

    public function getId(): string
    {
        return 'packstub-agents';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            AgentTokenResource::class,
            PendingApprovalResource::class,
            AgentAuditEntryResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
