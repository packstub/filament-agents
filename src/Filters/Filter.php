<?php

namespace Packstub\Agents\Filters;

use BackedEnum;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;

/**
 * One entry of a resource's filter vocabulary: what the model may pass
 * (the JSON schema), how the value is cleaned, and how it narrows a query.
 *
 *   Filter::enum('status', OrderStatus::class)->multiple()
 *       ->apply(fn (Builder $q, array $status) => $q->whereIn('status', $status))
 *
 * The same object drives show-table (a live Filament table for the person)
 * and the app's search tools (rows for the model), so "orders waiting for
 * a phone call" means the same thing in both.
 */
class Filter
{
    public const string TEXT = 'text';

    public const string ENUM = 'enum';

    public const string BOOLEAN = 'boolean';

    public const string FLAG = 'flag';

    public const string DATE = 'date';

    public const string NUMBER = 'number';

    protected bool $multiple = false;

    protected ?string $description = null;

    protected ?Closure $apply = null;

    /** @param  list<string>  $values */
    final protected function __construct(protected string $key, protected string $type, protected array $values = []) {}

    /** Free text. */
    public static function text(string $key): static
    {
        return new static($key, self::TEXT);
    }

    /** One of a fixed set: a backed enum class or a list of values. */
    public static function enum(string $key, string|array $values): static
    {
        if (is_string($values) && is_subclass_of($values, BackedEnum::class)) {
            $values = array_map(fn (BackedEnum $case) => (string) $case->value, $values::cases());
        }

        return new static($key, self::ENUM, array_values(array_map('strval', (array) $values)));
    }

    /** True or false; the closure runs whenever the model sets it. */
    public static function boolean(string $key): static
    {
        return new static($key, self::BOOLEAN);
    }

    /** A switch the closure only runs for when true ("open_only"). */
    public static function flag(string $key): static
    {
        return new static($key, self::FLAG);
    }

    /** YYYY-MM-DD. */
    public static function date(string $key): static
    {
        return new static($key, self::DATE);
    }

    public static function number(string $key): static
    {
        return new static($key, self::NUMBER);
    }

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** fn (Builder $query, mixed $value): void — the value is already normalized. */
    public function apply(Closure $callback): static
    {
        $this->apply = $callback;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return list<string> */
    public function values(): array
    {
        return $this->values;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** What the model sees in the schema: the description plus the allowed values. */
    public function hint(): string
    {
        $parts = array_filter([
            $this->description,
            $this->type === self::ENUM ? implode('|', $this->values) : null,
            $this->type === self::DATE ? 'YYYY-MM-DD' : null,
        ]);

        return implode(' ', $parts);
    }

    /** Clean a raw value; null means "not set", and the filter is dropped. */
    public function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($this->type) {
            self::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            self::FLAG => filter_var($value, FILTER_VALIDATE_BOOL) ? true : null,
            self::NUMBER => is_numeric($value) ? $value + 0 : null,
            self::ENUM => $this->multiple
                ? (array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== '')) ?: null)
                : (is_scalar($value) ? trim((string) $value) : null),
            default => is_scalar($value) ? (trim((string) $value) ?: null) : null,
        };
    }

    public function applyTo(Builder $query, mixed $value): void
    {
        if ($this->apply && $value !== null) {
            ($this->apply)($query, $value);
        }
    }

    /** The JSON schema entry. Loose mode (the union show-table schema) keeps enums as plain strings. */
    public function schema(JsonSchema $schema, bool $strict = true): mixed
    {
        $type = match ($this->type) {
            self::BOOLEAN, self::FLAG => $schema->boolean(),
            self::NUMBER => $schema->number(),
            self::ENUM => $strict && ! $this->multiple ? $schema->string()->enum($this->values) : $schema->string(),
            default => $schema->string(),
        };

        if ($this->multiple) {
            $type = $schema->array()->items($type);
        }

        return $this->hint() !== '' ? $type->description($this->hint()) : $type;
    }
}
