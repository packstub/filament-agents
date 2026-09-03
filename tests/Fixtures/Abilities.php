<?php

namespace Packstub\Agents\Tests\Fixtures;

/** The app's authorization, as tests see it: a list of abilities the current person has, plus a role label. */
class Abilities
{
    /** @var list<string> */
    public static array $allowed = ['*'];

    public static ?string $role = 'Owner';

    public static function allows(string $ability): bool
    {
        return in_array('*', self::$allowed, true) || in_array($ability, self::$allowed, true);
    }

    public static function reset(): void
    {
        self::$allowed = ['*'];
        self::$role = 'Owner';
    }
}
