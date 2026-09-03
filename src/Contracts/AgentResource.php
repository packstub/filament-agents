<?php

namespace Packstub\Agents\Contracts;

use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Filters\Filter;

/**
 * A Filament resource the assistant may show as a live table (show_table)
 * and use as page context ("the person opened this chat from Order
 * RO-00012"). Use the InteractsWithAgent trait for sensible defaults and
 * override what the domain needs.
 */
interface AgentResource
{
    /** The name the model uses for this table ("orders"). */
    public static function agentKey(): string;

    /**
     * How a record looks to the model: compact for lists, full for one record,
     * always with the panel url so the answer can link to it.
     *
     * @return array<string, mixed>
     */
    public static function agentSummary(Model $record, bool $full = false): array;

    /** "The person opened this chat from …" ("Order RO-00012"). */
    public static function agentContextLabel(Model $record): string;

    /**
     * The filter vocabulary the model may pass to show_table (and to the
     * app's own search tools).
     *
     * @return list<Filter>
     */
    public static function agentFilters(): array;
}
