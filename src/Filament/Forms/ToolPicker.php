<?php

namespace Packstub\Agents\Filament\Forms;

use Closure;
use Filament\Forms\Components\Field;

/**
 * The tool picker of the Create token modal: one table of the tools the
 * person's role allows (checkbox, title, Read / Write, what it does), instead
 * of two checkbox lists with the model-facing descriptions in full. State is
 * the list of ticked tool names. Write rows are switched off while the token
 * may not write; the action drops them anyway, so this is only what the
 * person sees.
 */
class ToolPicker extends Field
{
    protected string $view = 'packstub-agents::forms.tool-picker';

    /** @var array<string, array{title: string, description: string, readOnly: bool}> | Closure */
    protected array|Closure $tools = [];

    protected bool|Closure $isWriteEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->afterStateHydrated(fn (ToolPicker $component, $state) => $component->state(array_values((array) $state)));
        $this->dehydrateStateUsing(fn ($state) => array_values(array_filter((array) $state, 'is_string')));
    }

    /** @param  array<string, array{title: string, description: string, readOnly: bool}> | Closure  $tools */
    public function tools(array|Closure $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    public function writeEnabled(bool|Closure $condition = true): static
    {
        $this->isWriteEnabled = $condition;

        return $this;
    }

    /** @return array<string, array{title: string, description: string, readOnly: bool}> */
    public function getTools(): array
    {
        return $this->evaluate($this->tools);
    }

    public function isWriteEnabled(): bool
    {
        return (bool) $this->evaluate($this->isWriteEnabled);
    }
}
