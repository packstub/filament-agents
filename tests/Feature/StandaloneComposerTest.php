<?php

use Packstub\FilamentAgents\Pages\Chat;
use Packstub\FilamentAgents\Tests\Fixtures\Livewire\AskBox;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

// #88: the composer on a page of the app's own (a dashboard's home) threw Alpine errors and Send called nothing,
// since its bindings live in the chat page's agentChat scope. Standalone, it brings its own scope and sends the
// text to the host's method.
it('renders the composer standalone on a page of the app\'s own, with its own scope, and sends the text to the host\'s method', function () {
    actingAs($this->user());

    $box = livewire(AskBox::class);
    $html = $box->html();

    expect($html)->toContain("x-data=\"agentComposer({ method: 'ask' })\"")
        ->toContain('x-load-src=')->toContain('agent-composer.js')
        ->toContain('fi-agent-composer-model') // the picker, with the host's $model
        ->toContain('Ask anything…')->toContain('rows="3"');

    $box->call('ask', 'How many widgets are live?')->assertSet('asked', 'How many widgets are live?');

    // The picker off, for a host without a model property.
    livewire(AskBox::class, ['models' => false])->assertDontSee('fi-agent-composer-model', escape: false);

    // The chat page keeps its own scope.
    chat()->assertDontSee('agentComposer', escape: false);

    // The scope defines every binding the composer's markup reads.
    $script = file_get_contents(__DIR__.'/../../resources/js/agent-composer.js');
    $markup = file_get_contents(__DIR__.'/../../resources/views/components/composer.blade.php');
    foreach (['submit', 'autosize', 'typed', 'recall', 'pick', 'closePicker', 'cancelEdit', 'stop', 'dropFiles', 'pasteFiles'] as $method) {
        expect($markup)->toContain($method.'(')->and($script)->toContain($method.'(');
    }
    foreach (['busy', 'editing', 'stopping', 'turn', 'dragging', 'picker'] as $state) {
        expect($script)->toContain($state.':');
    }
    expect($script)->toContain('this.$wire[method](text)');
});
