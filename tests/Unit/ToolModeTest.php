<?php

namespace Packstub\Agents\Tests\Unit;

use Packstub\Agents\Enums\ToolMode;
use PHPUnit\Framework\TestCase;

class ToolModeTest extends TestCase
{
    public function test_a_grant_covers_its_own_mode_and_lower_modes(): void
    {
        $this->assertTrue(ToolMode::Read->covers(ToolMode::Read));
        $this->assertTrue(ToolMode::Write->covers(ToolMode::Read));
        $this->assertTrue(ToolMode::Write->covers(ToolMode::Write));
        $this->assertTrue(ToolMode::Destructive->covers(ToolMode::Write));
        $this->assertTrue(ToolMode::Destructive->covers(ToolMode::Destructive));
    }

    public function test_a_grant_never_covers_a_higher_mode(): void
    {
        $this->assertFalse(ToolMode::Read->covers(ToolMode::Write));
        $this->assertFalse(ToolMode::Read->covers(ToolMode::Destructive));
        $this->assertFalse(ToolMode::Write->covers(ToolMode::Destructive));
    }
}
