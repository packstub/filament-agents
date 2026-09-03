<?php

namespace Packstub\Agents\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Packstub\Agents\Filters\Filter;

/**
 * Defaults for AgentResource: the key from the resource slug, a summary of
 * title + url, the context label from the model label, and no filters.
 */
trait InteractsWithAgent
{
    public static function agentKey(): string
    {
        return Str::slug(Str::afterLast(static::getSlug(), '/'), '_');
    }

    /** @return array<string, mixed> */
    public static function agentSummary(Model $record, bool $full = false): array
    {
        return array_filter([
            'id' => $record->getKey(),
            'title' => static::getRecordTitle($record),
            'url' => static::agentRecordUrl($record),
        ], fn ($v) => $v !== null);
    }

    public static function agentContextLabel(Model $record): string
    {
        return Str::ucfirst(static::getModelLabel()).' '.static::getRecordTitle($record);
    }

    /** @return list<Filter> */
    public static function agentFilters(): array
    {
        return [];
    }

    /** The page a human would open for this record: view, else edit, else the list. */
    public static function agentRecordUrl(Model $record): ?string
    {
        try {
            if (static::hasPage('view')) {
                return static::getUrl('view', ['record' => $record]);
            }
            if (static::hasPage('edit')) {
                return static::getUrl('edit', ['record' => $record]);
            }

            return static::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }
}
