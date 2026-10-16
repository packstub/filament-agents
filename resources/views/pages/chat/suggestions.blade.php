{{-- A new chat: the assistant's name and its starter questions, each sent on click. Reads the page ($this). Part of
     pages/chat.blade.php, included with its variables; publish this file alone to change only this part. --}}
@if ($suggestions = $this->suggestions())
    {{-- A new chat: the assistant's name and its starter questions (Agent::suggestions), each sent on click. Gone
         the moment a question is on its way (busy), and not rendered once the conversation exists. --}}
    <div class="fi-chat-empty" x-show="! busy">
        <span class="fi-chat-empty-icon"><x-filament::icon icon="heroicon-o-sparkles" /></span>
        <p class="fi-chat-empty-title">{{ \Packstub\Agents\Facades\Agents::name() }}</p>
        <p class="fi-chat-empty-lead">{{ $this->contextLabel() ? __('Ask anything about :record, or start with one of these.', ['record' => $this->contextLabel()]) : __('Ask anything about your workspace, or start with one of these.') }}</p>
        <div class="fi-chat-suggestions">
            @foreach ($suggestions as $suggestion)
                <button type="button" class="fi-chat-suggestion" x-on:click="enqueue(@js($suggestion))">{{ $suggestion }}</button>
            @endforeach
        </div>
        <p class="fi-chat-empty-hint">{{ __('Type / for these questions, @ to mention a record.') }}</p>
    </div>
@endif
