<?php

namespace Packstub\Agents\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filters\Filter;

/**
 * The panel's resources as the assistant sees them: keyed by agentKey(),
 * with their filter vocabulary. Backs show_table, the embedded table and
 * the page context.
 */
class AgentResources
{
    /** @return array<string, class-string<AgentResource>> */
    public static function all(): array
    {
        $resources = [];

        foreach (Agents::resourceClasses() as $resource) {
            $resources[$resource::agentKey()] = $resource;
        }

        return $resources;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return class-string<AgentResource> */
    public static function find(string $key): string
    {
        return self::all()[$key] ?? throw new InvalidArgumentException(__('Unknown table ":key".', ['key' => $key]));
    }

    /** @return class-string<AgentResource>|null */
    public static function forModel(Model|string $model): ?string
    {
        $class = $model instanceof Model ? $model::class : $model;

        foreach (self::all() as $resource) {
            if ($resource::getModel() === $class) {
                return $resource;
            }
        }

        return null;
    }

    /** @return array<string, Filter> */
    public static function filters(string $key): array
    {
        $filters = [];

        foreach (self::find($key)::agentFilters() as $filter) {
            $filters[$filter->key()] = $filter;
        }

        return $filters;
    }

    /**
     * Keep only the filters the table knows, normalized; unknown keys and
     * empty values are dropped.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalizeFilters(string $key, array $raw): array
    {
        $filters = [];

        foreach (self::filters($key) as $name => $filter) {
            if (! array_key_exists($name, $raw)) {
                continue;
            }

            $value = $filter->normalize($raw[$name]);

            if ($value !== null) {
                $filters[$name] = $value;
            }
        }

        return $filters;
    }

    /** @param  array<string, mixed>  $filters  already normalized */
    public static function apply(string $key, Builder $query, array $filters): Builder
    {
        $known = self::filters($key);

        foreach ($filters as $name => $value) {
            if (isset($known[$name])) {
                $known[$name]->applyTo($query, $value);
            }
        }

        return $query;
    }

    /**
     * The JSON schema of the filters: one table's (strict) or the union of
     * every table's (loose, each key described per table).
     *
     * @return array<string, mixed>
     */
    public static function filterSchema(JsonSchema $schema, ?string $key = null): array
    {
        if ($key !== null) {
            return collect(self::filters($key))->map(fn (Filter $f) => $f->schema($schema))->all();
        }

        /** @var array<string, array{filter: Filter, hints: list<string>}> $union */
        $union = [];

        foreach (self::all() as $table => $resource) {
            foreach ($resource::agentFilters() as $filter) {
                $union[$filter->key()] ??= ['filter' => $filter, 'hints' => []];
                $hint = $filter->hint();
                $union[$filter->key()]['hints'][] = $table.($hint !== '' ? ': '.$hint : '');
            }
        }

        return collect($union)->map(function (array $entry) use ($schema) {
            $type = $entry['filter']->schema($schema, strict: false);

            return $type->description(implode('; ', $entry['hints']).'.');
        })->all();
    }
}
