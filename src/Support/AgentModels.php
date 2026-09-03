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
     * own key in place for this request when it has one.
     *
     * @return array{provider: string, model: string, effort: ?string}
     */
    public static function resolve(?string $key = null): array
    {
        $provider = self::provider();
        $catalog = self::catalog($provider);
        $entry = $catalog[$key ?? self::current()] ?? reset($catalog) ?: ['model' => null, 'effort' => null];

        self::applyWorkspaceKey($provider);

        $model = $entry['model'] ?? null;
        if (! $model) {
            $textProvider = app(AiManager::class)->textProvider($provider);
            $model = ($key ?? self::current()) === 'fast' ? $textProvider->cheapestTextModel() : $textProvider->smartestTextModel();
        }

        return ['provider' => $provider, 'model' => $model, 'effort' => $entry['effort'] ?? null];
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

        return $models[$provider] ?? $models['anthropic'] ?? ['auto' => ['label' => 'Auto', 'model' => null, 'effort' => null]];
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
