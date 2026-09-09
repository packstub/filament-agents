<?php

namespace Packstub\Agents\Support;

use Laravel\Ai\AiManager;
use Laravel\Ai\Enums\Lab;
use Packstub\Agents\Ai\WorkspaceCredentials;
use Packstub\Agents\Facades\Agents;

/**
 * Which provider and model a turn runs on.
 *
 * Provider: the workspace's own (the credentials callback on AgentsPlugin)
 * or the platform default (AGENT_PROVIDER + the key in config/ai.php).
 * Model: the picker next to the composer (Auto / Fast / Deep), remembered in
 * the session, defaulting to the workspace's preferred entry.
 */
class AgentModels
{
    public const string SESSION_KEY = 'packstub-agents.model';

    /** The provider name ('anthropic', 'openai', …) this workspace runs on. */
    public static function provider(): string
    {
        $own = self::credentials();

        return $own?->provider && $own->apiKey ? $own->provider : (string) config('packstub-agents.provider', 'anthropic');
    }

    /** True when there is a key to talk to the provider (the workspace's own or the platform's) and the workspace is not switched off. */
    public static function enabled(): bool
    {
        if (Agents::tenant() && ! AgentLimits::effective()['enabled']) {
            return false;
        }

        if (config('packstub-agents.enabled') !== null) {
            return filter_var(config('packstub-agents.enabled'), FILTER_VALIDATE_BOOL);
        }

        return filled(self::credentials()?->apiKey) || filled(config('ai.providers.'.config('packstub-agents.provider').'.key'));
    }

    /** @return array<string, string> key => label */
    public static function options(): array
    {
        return collect(self::catalog())->map(fn (array $m) => $m['label'])->all();
    }

    public static function current(): string
    {
        $catalog = self::catalog();
        $key = session(self::SESSION_KEY) ?? self::credentials()?->model ?? 'auto';

        return array_key_exists($key, $catalog) ? $key : (string) array_key_first($catalog);
    }

    public static function remember(string $key): void
    {
        if (array_key_exists($key, self::catalog())) {
            session([self::SESSION_KEY => $key]);
        }
    }

    /**
     * Resolve a picker key to what the prompt needs, and put the workspace's
     * own key in place for this request when it has one. `providers` is the
     * ordered provider => model list the turn runs on: the provider first,
     * then the failover providers with the same picker key on their own
     * catalog — laravel/ai moves down the list when one refuses the turn.
     *
     * @return array{provider: string, model: string, effort: ?string, providers: array<string, string>}
     */
    public static function resolve(?string $key = null): array
    {
        $provider = self::provider();
        $catalog = self::catalog($provider);
        $key ??= self::current();
        $entry = $catalog[$key] ?? reset($catalog) ?: ['model' => null, 'effort' => null];

        self::applyWorkspaceKey($provider);

        $model = $entry['model'] ?? null;
        if (! $model) {
            $textProvider = app(AiManager::class)->textProvider($provider);
            $model = $key === 'fast' ? $textProvider->cheapestTextModel() : $textProvider->smartestTextModel();
        }

        $providers = [$provider => $model];
        foreach (self::failover() as $fallback) {
            $providers[$fallback] = self::modelFor($fallback, $key);
        }

        return ['provider' => $provider, 'model' => $model, 'effort' => $entry['effort'] ?? null, 'providers' => $providers];
    }

    /**
     * The providers a turn falls back to, in order (config `failover`): those with a key in config/ai.php, the
     * provider itself left out. A workspace on its own key stays on it — a fallback would run on the platform's.
     *
     * @return list<string>
     */
    public static function failover(): array
    {
        $own = self::credentials();

        if ($own?->provider && $own->apiKey) {
            return [];
        }

        $provider = self::provider();

        return collect((array) config('packstub-agents.failover', []))
            ->filter(fn ($name) => is_string($name) && $name !== '' && $name !== $provider && filled(config("ai.providers.{$name}.key")))
            ->unique()
            ->values()
            ->all();
    }

    /** How a provider is called in the UI ('openai' → OpenAI). */
    public static function providerLabel(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'Anthropic',
            'openai' => 'OpenAI',
            'gemini' => 'Gemini',
            'xai' => 'xAI',
            'openrouter' => 'OpenRouter',
            'deepseek' => 'DeepSeek',
            default => ucfirst($provider),
        };
    }

    /** The model a picker key maps to on a given provider, without touching keys or session. */
    public static function modelFor(string $provider, ?string $key = null): string
    {
        $key ??= self::current();
        $catalog = self::catalog($provider);
        $model = $catalog[$key]['model'] ?? null;
        if ($model) {
            return $model;
        }

        $textProvider = app(AiManager::class)->textProvider($provider);

        return $key === 'fast' ? $textProvider->cheapestTextModel() : $textProvider->smartestTextModel();
    }

    /** @return array<string, array{label: string, model: ?string, effort: ?string}> */
    public static function catalog(?string $provider = null): array
    {
        $provider ??= self::provider();
        $models = config('packstub-agents.models', []);

        return $models[$provider] ?? self::genericCatalog();
    }

    /**
     * A provider without entries in config (Ollama, OpenRouter, Mistral, Groq…): its smartest model as Auto and its
     * cheapest as Fast, as laravel/ai knows them, with no effort since the knob differs per provider.
     *
     * @return array<string, array{label: string, model: ?string, effort: ?string}>
     */
    protected static function genericCatalog(): array
    {
        return [
            'auto' => ['label' => 'Auto', 'model' => null, 'effort' => null],
            'fast' => ['label' => 'Fast', 'model' => null, 'effort' => null],
        ];
    }

    /** Provider name → Lab enum, for providerOptions() checks. */
    public static function lab(string $provider): ?Lab
    {
        return Lab::tryFrom($provider);
    }

    protected static function applyWorkspaceKey(string $provider): void
    {
        $own = self::credentials();
        if (! $own?->apiKey || $own->provider !== $provider) {
            return;
        }

        if (config("ai.providers.{$provider}.key") !== $own->apiKey) {
            config(["ai.providers.{$provider}.key" => $own->apiKey]);
            app(AiManager::class)->forgetInstance($provider);
        }
    }

    protected static function credentials(): ?WorkspaceCredentials
    {
        return Agents::tenant() ? Agents::credentials() : null;
    }
}
