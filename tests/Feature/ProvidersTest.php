<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;

it('ships picker entries for Gemini and xAI', function () {
    config(['packstub-agents.provider' => 'gemini', 'ai.providers.gemini.key' => 'g-key']);
    expect(AgentModels::enabled())->toBeTrue()
        ->and(AgentModels::options())->toHaveKeys(['auto', 'fast', 'deep'])
        ->and(AgentModels::resolve('auto'))->toBe(['provider' => 'gemini', 'model' => 'gemini-3.8-flash', 'effort' => 'medium'])
        ->and(AgentModels::resolve('fast'))->toBe(['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'effort' => 'low'])
        ->and(AgentModels::resolve('deep'))->toBe(['provider' => 'gemini', 'model' => 'gemini-3.8-flash', 'effort' => 'high']);

    config(['packstub-agents.provider' => 'xai', 'ai.providers.xai.key' => 'x-key']);
    expect(AgentModels::resolve('auto'))->toBe(['provider' => 'xai', 'model' => 'grok-4.6', 'effort' => 'medium'])
        ->and(AgentModels::resolve('deep'))->toBe(['provider' => 'xai', 'model' => 'grok-4.6', 'effort' => 'xhigh']);
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
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-3.8-flash:generateContent')
        && $request['generationConfig']['thinkingConfig']['thinkingLevel'] === 'HIGH'
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
        && $request['model'] === 'grok-4.6'
        && $request['reasoning'] === ['effort' => 'xhigh']);
});
