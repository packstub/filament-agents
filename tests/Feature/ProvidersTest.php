<?php

use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Packstub\Agents\Ai\WorkspaceCredentials;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;

it('ships picker entries for Gemini and xAI', function () {
    // The models and efforts are the made-up catalog tests/TestCase.php pins, not the shipped defaults.
    config(['packstub-agents.provider' => 'gemini', 'ai.providers.gemini.key' => 'g-key']);
    expect(AgentModels::enabled())->toBeTrue()
        ->and(AgentModels::options())->toHaveKeys(['auto', 'fast', 'deep'])
        ->and(AgentModels::resolve('auto'))->toBe(['provider' => 'gemini', 'model' => 'test-gemini-auto', 'effort' => 'medium', 'providers' => ['gemini' => 'test-gemini-auto']])
        ->and(AgentModels::resolve('fast'))->toBe(['provider' => 'gemini', 'model' => 'test-gemini-fast', 'effort' => 'low', 'providers' => ['gemini' => 'test-gemini-fast']])
        ->and(AgentModels::resolve('deep'))->toBe(['provider' => 'gemini', 'model' => 'test-gemini-deep', 'effort' => 'high', 'providers' => ['gemini' => 'test-gemini-deep']]);

    config(['packstub-agents.provider' => 'xai', 'ai.providers.xai.key' => 'x-key']);
    expect(AgentModels::resolve('auto'))->toBe(['provider' => 'xai', 'model' => 'grok-test-auto', 'effort' => 'medium', 'providers' => ['xai' => 'grok-test-auto']])
        ->and(AgentModels::resolve('deep'))->toBe(['provider' => 'xai', 'model' => 'grok-test-deep', 'effort' => 'xhigh', 'providers' => ['xai' => 'grok-test-deep']]);
});

it('resolves the failover list to the same picker key on each provider that has a key', function () {
    config([
        'packstub-agents.provider' => 'anthropic',
        'packstub-agents.failover' => ['gemini', 'anthropic', 'openai', 'gemini', 'ollama'],
        'ai.providers.anthropic.key' => 'a-key',
        'ai.providers.gemini.key' => 'g-key',
        'ai.providers.openai.key' => null, // no key: skipped rather than failing the turn with an auth error
        'ai.providers.ollama.key' => 'unused',
    ]);

    // The first choice leads, then the fallbacks in config order — itself and duplicates dropped, a keyless provider
    // left out, a provider without picker entries on its smartest (Auto) or cheapest (Fast) model.
    expect(AgentModels::failover())->toBe(['gemini', 'ollama'])
        ->and(AgentModels::resolve('deep')['providers'])->toBe(['anthropic' => 'test-claude-deep', 'gemini' => 'test-gemini-deep', 'ollama' => AgentModels::modelFor('ollama', 'deep')])
        ->and(AgentModels::resolve('fast')['providers'])->toBe(['anthropic' => 'test-claude-fast', 'gemini' => 'test-gemini-fast', 'ollama' => AgentModels::modelFor('ollama', 'fast')])
        ->and(AgentModels::resolve('fast')['providers']['ollama'])->not->toBe(AgentModels::resolve('deep')['providers']['ollama']);

    // The options a fallback gets are read for its own model: a reasoning effort on OpenAI's gpt-5, none for the
    // first choice's Claude name it would otherwise be asked about.
    config(['ai.providers.openai.key' => 'o-key']);
    $agent = (new WidgetAgent(modelKey: 'deep'))->withModel('claude-opus-5')->withModels(AgentModels::resolve('deep')['providers']);
    expect(AgentModels::resolve('deep')['providers']['openai'])->toStartWith('gpt-5')
        ->and($agent->providerOptions('openai'))->toBe(['reasoning' => ['effort' => 'high']])
        ->and((new WidgetAgent(modelKey: 'deep'))->withModel('claude-opus-5')->providerOptions('openai'))->toBe([]);

    // A workspace on its own key stays on its provider: a fallback would run on the platform's key.
    Filament::setTenant($this->user(), isQuiet: true);
    Agents::credentialsUsing(fn () => new WorkspaceCredentials('anthropic', 'ws-key'));
    expect(AgentModels::failover())->toBe([])
        ->and(AgentModels::resolve('deep')['providers'])->toBe(['anthropic' => 'test-claude-deep']);

    expect(AgentModels::providerLabel('openai'))->toBe('OpenAI')
        ->and(AgentModels::providerLabel('xai'))->toBe('xAI')
        ->and(AgentModels::providerLabel('mistral'))->toBe('Mistral');
});

it('runs any other laravel/ai provider on its smartest and cheapest models', function () {
    config(['packstub-agents.provider' => 'ollama', 'ai.providers.ollama.key' => 'unused']);

    expect(AgentModels::options())->toBe(['auto' => 'Auto', 'fast' => 'Fast'])
        ->and(AgentModels::current())->toBe('auto')
        ->and(AgentModels::resolve('auto')['provider'])->toBe('ollama')
        ->and(AgentModels::resolve('auto')['model'])->not->toStartWith('claude')
        ->and(AgentModels::resolve('auto')['effort'])->toBeNull()
        ->and(AgentModels::resolve('fast')['model'])->not->toStartWith('claude')
        ->and((new WidgetAgent(modelKey: 'auto'))->providerOptions('ollama'))->toBe([]);

    AgentModels::remember('deep'); // not offered on this provider
    expect(AgentModels::current())->toBe('auto');
});

it('turns the effort into a Gemini thinking level and an xAI reasoning effort', function () {
    config([
        'packstub-agents.models.gemini.auto.effort' => 'medium',
        'packstub-agents.models.gemini.fast.effort' => 'low',
        'packstub-agents.models.gemini.deep.effort' => 'high',
        'packstub-agents.models.xai.fast' => ['label' => 'Fast', 'model' => 'grok-effort-fast', 'effort' => 'low'],
        'packstub-agents.models.xai.deep' => ['label' => 'Deep', 'model' => 'grok-effort-deep', 'effort' => 'xhigh'],
    ]);

    expect((new WidgetAgent(modelKey: 'auto'))->providerOptions('gemini'))->toBe(['thinkingConfig' => ['thinkingLevel' => 'MEDIUM']])
        ->and((new WidgetAgent(modelKey: 'fast'))->providerOptions('gemini'))->toBe(['thinkingConfig' => ['thinkingLevel' => 'LOW']])
        ->and((new WidgetAgent(modelKey: 'deep'))->providerOptions('gemini'))->toBe(['thinkingConfig' => ['thinkingLevel' => 'HIGH']])
        ->and((new WidgetAgent(modelKey: 'deep'))->providerOptions('xai'))->toBe(['reasoning' => ['effort' => 'xhigh']])
        ->and((new WidgetAgent(modelKey: 'fast'))->providerOptions('xai'))->toBe(['reasoning' => ['effort' => 'low']])
        ->and((new WidgetAgent(modelKey: 'fast', model: 'grok-4.20-non-reasoning'))->providerOptions('xai'))->toBe([])
        ->and(WidgetAgent::supportsReasoning('grok-4.6', 'xai'))->toBeTrue()
        ->and(WidgetAgent::supportsReasoning('grok-4.20-non-reasoning', 'xai'))->toBeFalse()
        ->and(WidgetAgent::supportsReasoning('gpt-5.2'))->toBeTrue();

    config(['packstub-agents.models.gemini.deep.effort' => 'xhigh']);
    expect((new WidgetAgent(modelKey: 'deep'))->providerOptions('gemini'))->toBe(['thinkingConfig' => ['thinkingLevel' => 'HIGH']]);

    config(['packstub-agents.models.gemini.fast.effort' => null]);
    expect((new WidgetAgent(modelKey: 'fast'))->providerOptions('gemini'))->toBe([]);
});

it('sends the model and the thinking level to Gemini', function () {
    actingAs($this->user());
    config(['packstub-agents.provider' => 'gemini', 'ai.providers.gemini.key' => 'g-key']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Two widgets are live.']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
        ]),
    ]);

    $resolved = AgentModels::resolve('deep');
    $response = (new WidgetAgent(modelKey: 'deep'))->withModel($resolved['model'])
        ->prompt('How many widgets are live?', provider: $resolved['provider'], model: $resolved['model']);

    expect((string) $response)->toBe('Two widgets are live.');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "models/{$resolved['model']}:generateContent")
        && $request['generationConfig']['thinkingConfig']['thinkingLevel'] === strtoupper($resolved['effort'])
        && str_contains($request->body(), 'You are Ask Widgets'));
});

it('sends the model and the reasoning effort to xAI', function () {
    actingAs($this->user());
    config(['packstub-agents.provider' => 'xai', 'ai.providers.xai.key' => 'x-key']);
    Http::fake([
        'api.x.ai/*' => Http::response([
            'id' => 'resp_1',
            'model' => 'grok-4.6',
            'output' => [['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Two widgets are live.', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]),
    ]);

    $resolved = AgentModels::resolve('deep');
    $response = (new WidgetAgent(modelKey: 'deep'))->withModel($resolved['model'])
        ->prompt('How many widgets are live?', provider: $resolved['provider'], model: $resolved['model']);

    expect((string) $response)->toBe('Two widgets are live.');
    Http::assertSent(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/responses')
        && $request['model'] === $resolved['model']
        && $request['reasoning'] === ['effort' => $resolved['effort']]);
});
