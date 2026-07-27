<?php

namespace Packstub\Agents\Enums;

enum AuditDecision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Allowed => 'Allowed',
            self::Denied => 'Denied',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Applied => 'Applied',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $decision): array => [$decision->value => $decision->label()])
            ->all();
    }
}
