<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Packstub\Agents\Actions\CreateAgentToken as CreateAgentTokenAction;
use Packstub\Agents\Filament\Resources\AgentTokens\AgentTokenResource;

class CreateAgentToken extends CreateRecord
{
    protected static string $resource = AgentTokenResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $grants = collect($data['grants'] ?? [])
            ->mapWithKeys(fn (array $grant): array => [$grant['tool'] => $grant['mode']])
            ->all();

        $result = app(CreateAgentTokenAction::class)->handle(
            name: $data['name'],
            grants: $grants,
            expiresAt: filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
        );

        session()->flash('created_agent_token_secret', $result->plainTextToken);

        return $result->agentToken;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
