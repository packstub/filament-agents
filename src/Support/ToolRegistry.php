<?php

namespace Packstub\Agents\Support;

use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Mcp\GovernedTool;

class ToolRegistry
{
    /**
     * @return array<int, class-string<GovernedTool>>
     */
    public static function classes(): array
    {
        return config('packstub-agents.tools', []);
    }

    /**
     * @return array<int, GovernedTool>
     */
    public static function tools(): array
    {
        return array_map(fn (string $class): GovernedTool => app($class), self::classes());
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(fn (GovernedTool $tool): string => $tool->name(), self::tools());
    }

    /**
     * Options for grant selects: tool name => "Title — mode".
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::tools())
            ->mapWithKeys(fn (GovernedTool $tool): array => [
                $tool->name() => sprintf('%s — %s', $tool->title(), $tool::requiredMode()->label()),
            ])
            ->all();
    }

    public static function resolve(string $toolName): ?GovernedTool
    {
        return collect(self::tools())
            ->first(fn (GovernedTool $tool): bool => $tool->name() === $toolName);
    }

    public static function requiredMode(string $toolName): ?ToolMode
    {
        $tool = self::resolve($toolName);

        return $tool === null ? null : $tool::requiredMode();
    }
}
