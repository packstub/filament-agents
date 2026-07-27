<?php

namespace Packstub\Agents\Filament\Resources\PendingApprovals\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Packstub\Agents\Approvals\ApplyPendingApproval;
use Packstub\Agents\Approvals\RejectPendingApproval;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Filament\Resources\PendingApprovals\PendingApprovalResource;

class ViewPendingApproval extends ViewRecord
{
    protected static string $resource = PendingApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('The proposed change will be applied immediately, under the agent token\'s re-validated grant.')
                ->visible(fn (): bool => $this->record->isPending())
                ->action(function (): void {
                    $approval = app(ApplyPendingApproval::class)->handle($this->record, auth()->user());

                    if ($approval->status === ApprovalStatus::Applied) {
                        Notification::make()
                            ->title('Change applied')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Apply failed')
                            ->body($approval->result['error'] ?? 'The change could not be applied.')
                            ->danger()
                            ->send();
                    }

                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),
            Action::make('reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->isPending())
                ->action(function (): void {
                    app(RejectPendingApproval::class)->handle($this->record, auth()->user());

                    Notification::make()
                        ->title('Proposal rejected')
                        ->success()
                        ->send();

                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
