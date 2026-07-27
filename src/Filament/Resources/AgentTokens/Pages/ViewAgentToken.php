<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Packstub\Agents\Filament\Resources\AgentTokens\AgentTokenResource;

class ViewAgentToken extends ViewRecord
{
    protected static string $resource = AgentTokenResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $plainTextToken = session()->pull('created_agent_token_secret');

        if (! is_string($plainTextToken) || $plainTextToken === '') {
            return;
        }

        Notification::make()
            ->title('Copy this bearer token now')
            ->body(new HtmlString(sprintf(
                'It will not be shown again:<br><strong>%s</strong>',
                e($plainTextToken),
            )))
            ->warning()
            ->persistent()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('revoke')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->revoked_at === null)
                ->action(function (): void {
                    $this->record->forceFill(['revoked_at' => now()])->save();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
