<?php

namespace Packstub\FilamentAgents\Tests\Fixtures\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/** A page of the app's own with the composer on it: a question goes to ask(), not to the chat page. */
class AskBox extends Component
{
    public string $model = 'auto';

    public bool $models = true;

    public ?string $asked = null;

    public function ask(string $text): void
    {
        $this->asked = $text;
    }

    public function render(): View
    {
        return view()->file(__DIR__.'/ask-box.blade.php');
    }
}
