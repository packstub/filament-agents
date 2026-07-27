<?php

namespace Packstub\Agents\Enums;

enum ToolMode: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read',
            self::Write => 'Write',
            self::Destructive => 'Destructive',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Read => 0,
            self::Write => 1,
            self::Destructive => 2,
        };
    }

    /**
     * Whether a grant of this mode is sufficient for a tool requiring $required.
     */
    public function covers(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }
}
