<?php

use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Packstub\Agents\Ai\WorkspaceCredentials;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

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

it('offers entries of more than one provider on one picker', function () {
    config([
        'packstub-agents.provider' => 'anthropic',
        'packstub-agents.failover' => ['gemini', 'openai'],
        'ai.providers.anthropic.key' => 'a-key',
        'ai.providers.gemini.key' => 'g-key',
        'ai.providers.openai.key' => null,
        'ai.providers.ollama.key' => 'unused',
        // Entries under the platform provider that run elsewhere: on Gemini, on a local Ollama that must never fail
        // over to a cloud provider, and on OpenAI, which has no key and so is not offered.
        'packstub-agents.models.anthropic.flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'test-gemini-flash', 'effort' => 'low'],
        'packstub-agents.models.anthropic.local' => ['label' => 'Local', 'provider' => 'ollama', 'model' => 'llama-test', 'effort' => null, 'failover' => []],
        'packstub-agents.models.anthropic.gpt' => ['label' => 'GPT', 'provider' => 'openai', 'model' => 'gpt-5-test', 'effort' => 'low'],
    ]);

    expect(AgentModels::options())->toBe(['auto' => 'Auto', 'fast' => 'Fast', 'deep' => 'Deep', 'flash' => 'Gemini Flash', 'local' => 'Local'])
        ->and(AgentModels::groups())->toBe(['anthropic' => ['auto' => 'Auto', 'fast' => 'Fast', 'deep' => 'Deep'], 'gemini' => ['flash' => 'Gemini Flash'], 'ollama' => ['local' => 'Local']])
        ->and(AgentModels::catalog()['auto']['provider'])->toBe('anthropic');

    // A Gemini entry runs on Gemini with its own model and effort, and fails over down the list with Gemini left out —
    // to the platform provider too when it is listed; the same key on a fallback without it runs its smartest model.
    config(['packstub-agents.failover' => ['gemini', 'anthropic', 'openai']]);
    expect(AgentModels::resolve('flash'))->toMatchArray(['provider' => 'gemini', 'model' => 'test-gemini-flash', 'effort' => 'low'])
        ->and(AgentModels::failover('flash'))->toBe(['anthropic'])
        ->and(array_keys(AgentModels::resolve('flash')['providers']))->toBe(['gemini', 'anthropic'])
        ->and(AgentModels::resolve('flash')['providers']['anthropic'])->not->toBe('test-gemini-flash')
        ->and(AgentModels::resolve('auto')['providers'])->toBe(['anthropic' => 'test-claude-auto', 'gemini' => 'test-gemini-auto'])
        ->and(AgentModels::resolve('local')['providers'])->toBe(['ollama' => 'llama-test']);

    // The effort is the entry's on the provider it runs on, and nothing on a fallback that has no such key.
    expect((new WidgetAgent(modelKey: 'flash'))->providerOptions('gemini'))->toBe(['thinkingConfig' => ['thinkingLevel' => 'LOW']])
        ->and((new WidgetAgent(modelKey: 'flash'))->providerOptions('anthropic'))->not->toHaveKey('output_config')
        ->and((new WidgetAgent(modelKey: 'deep'))->providerOptions('gemini'))->toBe(['thinkingConfig' => ['thinkingLevel' => 'HIGH']]);

    // Remembered and offered in the picker like any entry.
    AgentModels::remember('flash');
    expect(AgentModels::current())->toBe('flash');
    actingAs($this->user());
    // The model list of the composer: the entries under provider headings, the remembered one ticked.
    livewire(Chat::class)->assertSee('Gemini Flash')->assertSeeInOrder(['fi-dropdown-header', 'Anthropic', 'fi-dropdown-header', 'Gemini', 'fi-agent-composer-model-picked', 'Gemini Flash'])->assertDontSee('GPT');

    // The chat is on as long as some listed entry has a key.
    config(['packstub-agents.enabled' => null, 'ai.providers.anthropic.key' => null]);
    expect(AgentModels::enabled())->toBeTrue();
    config(['ai.providers.gemini.key' => null, 'ai.providers.ollama.key' => null]);
    expect(AgentModels::enabled())->toBeFalse()->and(AgentModels::options())->toBe(['auto' => 'Auto', 'fast' => 'Fast', 'deep' => 'Deep']);
});

it('shows a workspace on its own key only the entries of its provider', function () {
    config([
        'packstub-agents.provider' => 'anthropic',
        'packstub-agents.failover' => ['gemini'],
        'ai.providers.anthropic.key' => 'a-key',
        'ai.providers.gemini.key' => 'g-key',
        'packstub-agents.models.anthropic.flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'test-gemini-flash', 'effort' => 'low'],
        'packstub-agents.models.gemini.opus' => ['label' => 'Claude', 'provider' => 'anthropic', 'model' => 'test-claude-deep', 'effort' => 'high'],
        'packstub-agents.models.gemini.pro' => ['label' => 'Pro', 'provider' => 'gemini', 'model' => 'test-gemini-pro', 'effort' => 'high'],
    ]);
    Filament::setTenant($this->user(), isQuiet: true);

    // On the platform key: every entry whose provider has a key.
    expect(AgentModels::options())->toHaveKeys(['auto', 'fast', 'deep', 'flash']);

    // On its own Gemini key: Gemini's list without the entry that would run on the platform's Anthropic key, no
    // failover, and the platform's Anthropic key untouched.
    Agents::credentialsUsing(fn () => new WorkspaceCredentials('gemini', 'ws-key', 'pro'));
    expect(AgentModels::provider())->toBe('gemini')
        ->and(AgentModels::options())->toBe(['auto' => 'Auto', 'fast' => 'Fast', 'deep' => 'Deep', 'pro' => 'Pro'])
        ->and(AgentModels::groups())->toHaveCount(1)
        ->and(AgentModels::current())->toBe('pro')
        ->and(AgentModels::resolve('pro'))->toBe(['provider' => 'gemini', 'model' => 'test-gemini-pro', 'effort' => 'high', 'providers' => ['gemini' => 'test-gemini-pro']])
        ->and(AgentModels::resolve('opus')['provider'])->toBe('gemini')
        ->and(config('ai.providers.gemini.key'))->toBe('ws-key')
        ->and(config('ai.providers.anthropic.key'))->toBe('a-key');

    // A workspace on its own key for a provider without a platform key: its own entries, and the chat is on.
    config(['ai.providers.gemini.key' => null, 'ai.providers.anthropic.key' => null, 'packstub-agents.enabled' => null]);
    Agents::credentialsUsing(fn () => new WorkspaceCredentials('gemini', 'ws-key'));
    expect(AgentModels::enabled())->toBeTrue()->and(AgentModels::options())->toHaveKey('pro')->not->toHaveKey('opus');
});

it('names an entry without a label after its model', function () {
    expect(AgentModels::modelName('claude-opus-5'))->toBe('Claude Opus 5')
        ->and(AgentModels::modelName('claude-haiku-4-5'))->toBe('Claude Haiku 4.5')
        ->and(AgentModels::modelName('claude-sonnet-4-5-20250929'))->toBe('Claude Sonnet 4.5')
        ->and(AgentModels::modelName('gemini-3.5-flash-lite'))->toBe('Gemini 3.5 Flash Lite')
        ->and(AgentModels::modelName('gpt-5.2'))->toBe('GPT-5.2')
        ->and(AgentModels::modelName('gpt-5-mini'))->toBe('GPT-5 Mini')
        ->and(AgentModels::modelName('o4-mini'))->toBe('o4 Mini')
        ->and(AgentModels::modelName('grok-4.6'))->toBe('Grok 4.6')
        ->and(AgentModels::modelName('openai/gpt-5'))->toBe('GPT-5')
        ->and(AgentModels::modelName('qwen3.5:0.8b'))->toBe('Qwen3.5 0.8b')
        ->and(AgentModels::modelName('llama3.3'))->toBe('Llama3.3');

    // The shipped catalog has no labels: the picker shows the models, Auto and Deep on the same one told apart by the
    // key, an explicit label as it is, a null model by the name laravel/ai resolves it to.
    config([
        'packstub-agents.provider' => 'anthropic',
        'ai.providers.gemini.key' => 'g-key',
        'packstub-agents.models.anthropic' => [
            'auto' => ['label' => null, 'model' => 'claude-opus-5', 'effort' => 'medium'],
            'fast' => ['label' => null, 'model' => 'claude-haiku-4-5', 'effort' => null],
            'deep' => ['label' => null, 'model' => 'claude-opus-5', 'effort' => 'xhigh'],
            'flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'effort' => 'low'],
            'best' => ['label' => null, 'provider' => 'gemini', 'model' => null, 'effort' => null],
        ],
    ]);
    expect(AgentModels::options())->toBe([
        'auto' => 'Claude Opus 5',
        'fast' => 'Claude Haiku 4.5',
        'deep' => 'Claude Opus 5 · Deep',
        'flash' => 'Gemini Flash',
        'best' => AgentModels::modelName(AgentModels::modelFor('gemini', 'best')),
    ])->and(AgentModels::groups()['gemini'])->toHaveKeys(['flash', 'best']);

    actingAs($this->user());
    livewire(Chat::class)->assertSee('Claude Opus 5 · Deep')->assertDontSee('>Deep<');
});

it('runs any other laravel/ai provider on its smartest and cheapest models', function () {
    config(['packstub-agents.provider' => 'ollama', 'ai.providers.ollama.key' => 'unused']);

    // Shown by model name, as laravel/ai names them for the provider.
    expect(AgentModels::options())->toBe(['auto' => AgentModels::modelName(AgentModels::modelFor('ollama', 'auto')), 'fast' => AgentModels::modelName(AgentModels::modelFor('ollama', 'fast'))])
        ->and(AgentModels::options()['auto'])->not->toBe(AgentModels::options()['fast'])
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
