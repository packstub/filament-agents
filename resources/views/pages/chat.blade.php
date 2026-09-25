<x-filament-panels::page>
    @php
        $live = $this->live();
        $context = $this->history();
        $messages = $this->messages();
        $lastQuestionId = $messages->last(fn ($m) => $m['role'] === 'user' && ! $m['continuation'])['id'] ?? null;
        $lastQuestionVersions = $lastQuestionId ? $messages->firstWhere('id', $lastQuestionId)['versions'] : 0;
    @endphp
    <div
        class="fi-chat mx-auto flex w-full max-w-3xl flex-col gap-6 {{ $embedded ? 'fi-chat-embedded' : '' }}"
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('agent-chat', 'packstub/filament-agents') }}"
        x-data="agentChat()"
    >
        {{-- What the component reads per render. Kept out of x-data on purpose: a changed x-data expression makes the
             morph re-initialise the component, which would drop the outbox and the polling state. --}}
        <div
            x-ref="state"
            hidden
            data-prompt="{{ $prompt }}"
            data-auto-send="{{ $autoSend ? '1' : '' }}"
            data-poll="{{ $this->pollUrl() }}"
            data-stream="{{ $this->streamUrl() }}"
            data-active="{{ $live['active']['id'] ?? '' }}"
            data-interval="{{ \Packstub\Agents\Support\AgentTurns::pollInterval() }}"
            data-title="{{ $this->getTitle() }}"
            data-conversation="{{ $conversation ?? '' }}"
            data-suggestions="{{ json_encode($this->suggestions()) }}"
            data-mentions="{{ $this->canMention() ? '1' : '' }}"
            data-copied="{{ __('Copied') }}"
            data-copy="{{ __('Copy') }}"
        ></div>

        <div class="fi-chat-header flex items-center justify-between gap-3">
            <div class="min-w-0 flex-1" x-data="{ renaming: false, title: @js((string) $this->getTitle()) }">
                <div class="flex items-center gap-1.5" x-show="! renaming">
                    <h1 class="truncate text-xl font-semibold text-gray-950 dark:text-white">{{ $this->getTitle() }}</h1>
                    @if ($conversation)
                        <button type="button" class="fi-chat-header-tool" title="{{ __('Rename') }}" aria-label="{{ __('Rename') }}" x-on:click="renaming = true; $nextTick(() => $refs.title.select())">
                            <x-filament::icon icon="heroicon-m-pencil" class="h-4 w-4" />
                        </button>
                    @endif
                </div>
                @if ($conversation)
                    <form class="flex items-center gap-2" x-show="renaming" x-cloak x-on:submit.prevent="renaming = false; $wire.rename(title)">
                        <input x-ref="title" x-model="title" type="text" maxlength="100" class="fi-chat-rename-input" aria-label="{{ __('Chat title') }}" x-on:keydown.escape.prevent="renaming = false; title = @js((string) $this->getTitle())" />
                        <x-filament::button type="submit" size="xs">{{ __('Save') }}</x-filament::button>
                        <x-filament::link tag="button" type="button" size="sm" color="gray" :x-on:click="'renaming = false; title = '.\Illuminate\Support\Js::from((string) $this->getTitle())">{{ __('Cancel') }}</x-filament::link>
                    </form>
                @endif
                @if ($label = $this->contextLabel())
                    <p class="mt-0.5 text-xs text-gray-500">{{ __('About :record', ['record' => $label]) }}</p>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-1">
                @if ($conversation)
                    @php($pinned = $this->pinned())
                    <button type="button" wire:click="togglePin" class="fi-chat-header-tool {{ $pinned ? 'fi-chat-header-tool-on' : '' }}" title="{{ $pinned ? __('Unpin') : __('Pin') }}" aria-label="{{ $pinned ? __('Unpin') : __('Pin') }}" aria-pressed="{{ $pinned ? 'true' : 'false' }}">
                        <x-filament::icon :icon="$pinned ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'" class="h-4 w-4" />
                    </button>
                    <button type="button" wire:click="export" class="fi-chat-header-tool" title="{{ __('Export as Markdown') }}" aria-label="{{ __('Export as Markdown') }}">
                        <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                    </button>
                @endif
                @if ($embedded)
                    <x-filament::link :href="$this->pageUrl()" target="_top" size="sm" color="gray" icon="heroicon-m-arrow-top-right-on-square">{{ __('Open full page') }}</x-filament::link>
                    <x-filament::button tag="a" :href="\Packstub\Agents\Filament\Pages\Chat::getUrl(['embedded' => 1])" size="sm" color="gray" outlined icon="heroicon-m-plus">{{ __('New chat') }}</x-filament::button>
                @else
                    <x-filament::link :href="\Packstub\Agents\Filament\Pages\Chats::getUrl()" size="sm" color="gray" class="ms-1">{{ __('All chats') }}</x-filament::link>
                    <x-filament::button tag="a" :href="\Packstub\Agents\Filament\Pages\Chat::getUrl()" size="sm" color="gray" outlined icon="heroicon-m-plus" class="ms-1">{{ __('New chat') }}</x-filament::button>
                @endif
            </div>
        </div>

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

        <div class="flex flex-col gap-5" x-ref="transcript" role="log" aria-live="polite" aria-relevant="additions text" aria-label="{{ __('Conversation') }}">
            @if ($context && $context['source'])
                <p class="text-xs text-gray-500">
                    {{ __('Continued from') }}
                    <a href="{{ \Packstub\Agents\Filament\Pages\Chat::getUrl(['conversation' => $context['source']]) }}" class="font-medium text-primary-600 hover:underline">{{ $context['sourceTitle'] ?? __('an earlier chat') }}</a>
                    — {{ __('the assistant starts from a summary of it.') }}
                </p>
            @endif

            @foreach ($messages as $message)
                @if ($message['role'] === 'user')
                    @unless ($message['continuation'])
                        <div class="fi-chat-exchange flex items-end justify-end gap-2" wire:key="message-{{ $message['id'] }}">
                            @if ($message['editable'])
                                {{-- The last question: edit it and send it again (the answer is kept as a version). --}}
                                <button type="button" class="fi-chat-exchange-tools rounded p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Edit and send again') }}" aria-label="{{ __('Edit and send again') }}" x-show="! busy" x-on:click="editLast(@js($message['text']))">
                                    <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
                                </button>
                            @endif
                            <div class="fi-chat-question max-w-[85%]">
                                @if ($message['attachments'])
                                    <div class="fi-chat-attachments">
                                        @foreach ($message['attachments'] as $attachment)
                                            @if ($attachment['image'] && $attachment['url'])
                                                <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" class="fi-chat-attachment-image" title="{{ $attachment['name'] }}"><img src="{{ $attachment['url'] }}" alt="{{ $attachment['name'] }}" /></a>
                                            @else
                                                <a @if ($attachment['url']) href="{{ $attachment['url'] }}" target="_blank" rel="noopener" @endif class="fi-chat-attachment-file">
                                                    <x-filament::icon icon="heroicon-m-paper-clip" class="h-3.5 w-3.5" />
                                                    <span>{{ $attachment['name'] }}</span>
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                <div class="whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm">{!! $message['html'] !!}</div>
                                @if ($message['mentions'])
                                    <div class="fi-chat-mentions">
                                        @foreach ($message['mentions'] as $mention)
                                            <span class="fi-chat-mention" title="{{ $mention['ref'] }}">@ {{ $mention['label'] }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endunless
                    @if ($message['unanswered'])
                        {{-- Recorded but never answered (refused by a middleware, the provider failed, or the person stopped it): offer to send it again. --}}
                        <div class="flex items-center justify-end gap-2 text-xs text-gray-500" x-show="! busy" role="status">
                            <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-4 w-4 {{ ($live['ended']['reason'] ?? null) === 'refused' ? 'text-warning-500' : 'text-danger-500' }}" />
                            <span>
                                @if (($live['ended']['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::STOPPED)
                                    {{ __('Stopped before an answer.') }}
                                @elseif (($live['ended']['reason'] ?? null) === 'refused')
                                    {{ $live['ended']['error'] }}
                                @elseif (($live['ended']['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::FAILED)
                                    {{ __('The assistant could not answer.') }} {{ $live['ended']['error'] }}
                                @else
                                    {{ __('The assistant did not answer.') }}
                                @endif
                            </span>
                            <x-filament::link tag="button" size="sm" icon="heroicon-m-arrow-path" x-on:click="retry()">{{ __('Retry') }}</x-filament::link>
                        </div>
                    @endif
                @else
                    <div class="fi-chat-exchange flex flex-col gap-2 {{ $message['continued'] ? 'fi-chat-continued' : '' }}" wire:key="message-{{ $message['id'] }}">
                        @php($reads = collect($message['tools'])->where('readOnly', true)->values())
                        @if ($reads->isNotEmpty())
                            {{-- What the assistant looked up before answering: one line per call, the arguments and the result folded under it. --}}
                            <div class="fi-chat-timeline" x-data="{ open: null }">
                                @foreach ($reads as $i => $tool)
                                    <div class="fi-chat-timeline-row" wire:key="tool-{{ $message['id'] }}-{{ $tool['id'] ?? $i }}">
                                        <button type="button" class="fi-chat-timeline-call" x-on:click="open = open === {{ $i }} ? null : {{ $i }}" x-bind:aria-expanded="open === {{ $i }}">
                                            <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-chat-timeline-icon" />
                                            <span class="fi-chat-timeline-name">{{ $tool['name'] }}</span>
                                            @if ($summary = \Packstub\Agents\Filament\Pages\Chat::callSummary($tool['arguments']))
                                                <span class="fi-chat-timeline-args">{{ $summary }}</span>
                                            @endif
                                            @if ($outcome = \Packstub\Agents\Filament\Pages\Chat::resultSummary($tool['result']))
                                                <span class="fi-chat-timeline-result">{{ $outcome }}</span>
                                            @endif
                                            <x-filament::icon icon="heroicon-m-chevron-right" class="fi-chat-proposal-chevron" x-bind:class="{ 'fi-chat-proposal-chevron-open': open === {{ $i }} }" />
                                        </button>
                                        <div class="fi-chat-proposal-details" x-show="open === {{ $i }}" x-collapse x-cloak>
                                            @if ($tool['arguments'])
                                                <dl>
                                                    @foreach ($tool['arguments'] as $key => $value)
                                                        <dt>{{ \Illuminate\Support\Str::headline($key) }}</dt>
                                                        <dd>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                                                    @endforeach
                                                </dl>
                                            @endif
                                            @if (($result = \Packstub\Agents\Support\AgentChat::resultText($tool['result'])) !== null)
                                                <p class="fi-chat-proposal-label">{{ __('Result') }}</p>
                                                <pre>{{ \Illuminate\Support\Str::limit($result, 4000) }}</pre>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($message['tools'] as $tool)
                            @unless ($tool['readOnly'])
                                {{-- A proposed change: one question with a decision while it waits (the most prominent thing on the page),
                                     the outcome in the same place once decided. The exact call folds under the question. --}}
                                @php($state = $tool['pending'] ? 'pending' : ($tool['rejected'] ? 'rejected' : 'approved'))
                                <div class="fi-chat-proposal fi-chat-proposal-{{ $state }}" x-data="{ open: false }" wire:key="proposal-{{ $message['id'] }}-{{ $tool['id'] }}" data-state="{{ $state }}" role="group" aria-label="{{ $tool['question'] }}">
                                    <div class="fi-chat-proposal-row">
                                        <x-filament::icon :icon="$tool['pending'] ? 'heroicon-m-question-mark-circle' : ($tool['rejected'] ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')" class="fi-chat-proposal-icon" />
                                        <div class="fi-chat-proposal-body">
                                            <p class="fi-chat-proposal-question">{{ $tool['question'] }}</p>
                                            <button type="button" class="fi-chat-proposal-call" x-on:click="open = ! open" x-bind:aria-expanded="open">
                                                <x-filament::icon icon="heroicon-m-chevron-right" class="fi-chat-proposal-chevron" x-bind:class="{ 'fi-chat-proposal-chevron-open': open }" />
                                                <code>{{ $tool['tool'] }}</code>
                                                <span>{{ trans_choice('{0} no arguments|{1} :count argument|[2,*] :count arguments', count($tool['arguments'])) }}</span>
                                            </button>
                                        </div>
                                        <div class="fi-chat-proposal-decision">
                                            @if ($tool['pending'] && $tool['held'] !== null)
                                                {{-- Decided; it runs together with the other proposal of this answer once that one is decided too. --}}
                                                <span class="fi-chat-proposal-outcome">{{ $tool['held'] ? __('Approved, once the other proposal is decided') : __('Rejected, once the other proposal is decided') }}</span>
                                            @elseif ($tool['pending'])
                                                <div class="fi-chat-proposal-actions" x-show="! busy">
                                                    {{-- Bound attributes: a Blade directive inside a component tag's attribute is not compiled. --}}
                                                    <x-filament::button size="sm" icon="heroicon-m-check" :x-on:click="'decide('.\Illuminate\Support\Js::from($tool['id']).', true)'">{{ __('Approve') }}</x-filament::button>
                                                    <x-filament::button size="sm" color="gray" outlined :x-on:click="'decide('.\Illuminate\Support\Js::from($tool['id']).', false)'">{{ __('Reject') }}</x-filament::button>
                                                </div>
                                                <span class="fi-chat-proposal-outcome" x-show="busy" x-cloak>{{ __('Deciding…') }}</span>
                                            @else
                                                <span class="fi-chat-proposal-outcome">{{ $tool['rejected'] ? __('Rejected') : __('Approved') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($tool['pending'] && ($live['ended']['decision'] ?? false) && ($live['ended']['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::FAILED)
                                        {{-- The decision turn failed (the worker died, the provider or the history rejected it): say so here, where the buttons came back. --}}
                                        <p class="fi-chat-proposal-error" x-show="! busy">
                                            <x-filament::icon icon="heroicon-m-exclamation-circle" />
                                            <span>{{ __('The decision could not be applied.') }} {{ $live['ended']['error'] }}</span>
                                        </p>
                                    @endif
                                    <div class="fi-chat-proposal-details" x-show="open" x-collapse x-cloak>
                                        @if ($tool['arguments'])
                                            <dl>
                                                @foreach ($tool['arguments'] as $key => $value)
                                                    <dt>{{ \Illuminate\Support\Str::headline($key) }}</dt>
                                                    <dd>{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                                                @endforeach
                                            </dl>
                                        @endif
                                        @if (! $tool['rejected'] && ($result = \Packstub\Agents\Support\AgentChat::resultText($tool['result'])) !== null)
                                            <p class="fi-chat-proposal-label">{{ __('Result') }}</p>
                                            <pre>{{ $result }}</pre>
                                        @endif
                                    </div>
                                </div>
                            @endunless
                        @endforeach

                        @if (trim($message['html']) !== '')
                            <div class="fi-chat-md max-w-none text-sm text-gray-800 dark:text-gray-200" data-text="{{ $message['text'] }}">{!! $message['html'] !!}</div>
                        @endif

                        @foreach ($message['tables'] as $i => $table)
                            <div class="fi-chat-table-ctn" wire:key="table-{{ $message['id'] }}-{{ $i }}">
                                @livewire('packstub-agents.agent-table', $table, key('agent-table-'.$message['id'].'-'.$i))
                            </div>
                        @endforeach

                        @foreach ($message['charts'] as $i => $chart)
                            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900" wire:key="chart-{{ $message['id'] }}-{{ $i }}">
                                @if ($chart['title'])
                                    <p class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $chart['title'] }}</p>
                                @endif
                                <div
                                    x-load
                                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                                    wire:ignore
                                    x-data="chart({
                                        cachedData: @js($chart['data']),
                                        options: @js(['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['display' => count($chart['data']['datasets']) > 1 || in_array($chart['type'], ['pie', 'doughnut'])]]]),
                                        type: @js($chart['type']),
                                    })"
                                    class="fi-wi-chart-frame fi-wi-chart-frame-no-aspect-ratio fi-wi-chart-canvas-ctn fi-chat-chart"
                                    style="height: 18rem"
                                >
                                    <canvas x-ref="canvas" role="img" aria-label="{{ $chart['title'] }}" style="width: 100%; height: 100%"></canvas>
                                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                                </div>
                            </div>
                        @endforeach

                        @if (trim($message['html']) !== '' || $message['stopped'])
                            {{-- The controls under an answer show when it is hovered or focused (always on a touch screen); a rating
                                 that was given stays visible, and so do the notes on how the answer ended. --}}
                            <div class="fi-chat-answer-footer flex flex-wrap items-center gap-1 text-gray-400" x-data="{ note: false }">
                                <button type="button" class="fi-chat-answer-tool rounded p-1 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Copy') }}" aria-label="{{ __('Copy answer') }}" x-on:click="copy($event.currentTarget, @js($message['text']))">
                                    <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
                                </button>
                                <button type="button" wire:click="feedback('{{ $message['id'] }}', 'up')" class="rounded p-1 hover:text-success-600 {{ $message['rating'] === 'up' ? 'fi-chat-rated text-success-600' : 'fi-chat-answer-tool' }}" title="{{ __('Helpful') }}" aria-label="{{ __('Helpful') }}" aria-pressed="{{ $message['rating'] === 'up' ? 'true' : 'false' }}">
                                    <x-filament::icon icon="heroicon-m-hand-thumb-up" class="h-4 w-4" />
                                </button>
                                <button type="button" x-on:click="note = ! note; if (note) $nextTick(() => $refs.note.focus())" class="rounded p-1 hover:text-danger-600 {{ $message['rating'] === 'down' ? 'fi-chat-rated text-danger-600' : 'fi-chat-answer-tool' }}" title="{{ __('Not helpful') }}" aria-label="{{ __('Not helpful') }}" aria-pressed="{{ $message['rating'] === 'down' ? 'true' : 'false' }}">
                                    <x-filament::icon icon="heroicon-m-hand-thumb-down" class="h-4 w-4" />
                                </button>
                                @if ($message['regenerable'])
                                    {{-- The last answer: produce it again. --}}
                                    <button type="button" class="fi-chat-answer-tool rounded p-1 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Regenerate') }}" aria-label="{{ __('Regenerate') }}" x-show="! busy" x-on:click="regenerate()">
                                        <x-filament::icon icon="heroicon-m-arrow-path" class="h-4 w-4" />
                                    </button>
                                @endif
                                @if ($message['continuable'])
                                    <x-filament::link tag="button" size="sm" icon="heroicon-m-forward" class="ms-1" x-show="! busy" x-on:click="continueAnswer()">{{ __('Continue') }}</x-filament::link>
                                @endif
                                @if ($message['regenerable'] && $lastQuestionId && $lastQuestionVersions > 0)
                                    {{-- Earlier answers to this question: page through them, the current one being the newest. --}}
                                    @php($versions = $this->versions($lastQuestionId))
                                    <x-filament::dropdown placement="bottom-start" width="sm">
                                        <x-slot name="trigger">
                                            <button type="button" class="fi-chat-versions" x-show="! busy" aria-label="{{ __('Earlier answers') }}">
                                                <x-filament::icon icon="heroicon-m-chevron-left" class="h-3.5 w-3.5" />
                                                <span>{{ $lastQuestionVersions + 1 }}/{{ $lastQuestionVersions + 1 }}</span>
                                                <x-filament::icon icon="heroicon-m-chevron-right" class="h-3.5 w-3.5" />
                                            </button>
                                        </x-slot>
                                        <x-filament::dropdown.list>
                                            @foreach ($versions as $n => $version)
                                                <x-filament::dropdown.list.item wire:click="showVersion('{{ $lastQuestionId }}', {{ $version['id'] }})" x-on:click="close()" icon="heroicon-m-clock">
                                                    <span class="fi-chat-version-item">
                                                        <span class="fi-chat-version-label">{{ __('Answer :n', ['n' => $n + 1]) }} · {{ $version['at']?->format('H:i') }}</span>
                                                        <span class="fi-chat-version-snippet">{{ \Illuminate\Support\Str::limit($version['text'], 80) }}</span>
                                                    </span>
                                                </x-filament::dropdown.list.item>
                                            @endforeach
                                            <x-filament::dropdown.list.item icon="heroicon-m-check" :disabled="true">
                                                <span class="fi-chat-version-item"><span class="fi-chat-version-label">{{ __('Answer :n', ['n' => $lastQuestionVersions + 1]) }} · {{ __('current') }}</span></span>
                                            </x-filament::dropdown.list.item>
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                @endif
                                <span class="fi-chat-answer-tool ml-1 text-xs">{{ $message['at']?->format('H:i') }}</span>
                                @if ($message['stopped'])
                                    <span class="ml-1 text-xs">· {{ __('(stopped)') }}</span>
                                @endif
                                @if ($message['cutShort'])
                                    {{-- The provider ended the answer early; Regenerate (above, on the last answer) produces it again, Continue carries on. --}}
                                    <span class="ml-1 text-xs" title="{{ \Packstub\Agents\Support\AgentChat::cutShortText($message['cutShort']) }}">· {{ __('(cut short)') }}</span>
                                @endif
                                @if ($message['answeredBy'])
                                    {{-- The first choice refused the turn and a failover provider took it. --}}
                                    <span class="ml-1 text-xs" title="{{ __('The usual provider was unavailable; this answer came from :model.', ['model' => $message['answeredBy']['model']]) }}">· {{ __('(answered by :provider)', ['provider' => \Packstub\Agents\Support\AgentModels::providerLabel($message['answeredBy']['provider'])]) }}</span>
                                @endif
                                @if ($message['ratingNote'])
                                    <span class="ml-1 text-xs italic" title="{{ __('Your note') }}">· “{{ \Illuminate\Support\Str::limit($message['ratingNote'], 60) }}”</span>
                                @endif
                                {{-- A thumbs-down may say what went wrong; the rating is saved with or without the note. --}}
                                <form class="fi-chat-note basis-full" x-show="note" x-cloak x-on:submit.prevent="$wire.feedback(@js($message['id']), 'down', $refs.note.value); note = false">
                                    <input x-ref="note" type="text" maxlength="1000" class="fi-chat-note-input" placeholder="{{ __('What went wrong? (optional)') }}" aria-label="{{ __('What went wrong?') }}" x-on:keydown.escape.prevent="note = false" />
                                    <x-filament::button type="submit" size="xs" color="gray">{{ __('Send') }}</x-filament::button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach

            {{-- Live areas. The question being sent is drawn client-side the moment it is sent (agent-chat.js) and
                 handed over to the persisted transcript on re-render; the answer, the tools and the status come from the
                 turn's event stream (or the poll endpoint) while the job produces them. --}}
            <div wire:ignore class="flex justify-end empty:hidden">
                <template x-if="sending">
                    <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm" x-text="sending.text"></div>
                </template>
            </div>
            <div wire:ignore class="fi-chat-timeline fi-chat-timeline-live" x-show="turn !== null && live.tools.length > 0" x-cloak>
                <template x-for="(tool, i) in live.tools" :key="i">
                    <div class="fi-chat-timeline-row">
                        <span class="fi-chat-timeline-call">
                            <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-chat-timeline-icon" />
                            <span class="fi-chat-timeline-name" x-text="tool"></span>
                            <span class="fi-chat-timeline-result" x-show="i === live.tools.length - 1 && live.status.endsWith('…') && ! live.status.startsWith(@js(__('Writing')))" x-text="@js(__('running…'))"></span>
                        </span>
                    </div>
                </template>
            </div>
            <div wire:ignore class="fi-chat-md max-w-none text-sm text-gray-800 empty:hidden dark:text-gray-200" :class="{ 'fi-chat-streaming': turn !== null }" x-html="live.html" x-show="live.html !== ''"></div>
            <div wire:ignore class="flex items-center gap-2 text-xs text-gray-500" x-show="turn !== null" x-cloak role="status" aria-live="polite">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span x-text="live.status || @js(__('Thinking…'))"></span>
            </div>

            {{-- Questions waiting their turn on the server (kept per conversation): sent next, one at a time; editable until then. --}}
            <div class="flex flex-col gap-5 empty:hidden" x-ref="queued" data-turns="{{ json_encode(array_column($live['queued'], 'id')) }}">
                @foreach ($live['queued'] as $queued)
                    <div class="flex flex-col items-end gap-1" wire:key="queued-{{ $queued['id'] }}">
                        <div class="fi-chat-queued max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm px-4 py-2.5 text-sm">{{ $queued['text'] }}</div>
                        <div class="flex items-center gap-3 text-xs text-gray-400">
                            <span>{{ __('Queued') }}</span>
                            <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="editQueued(@js($queued['id']))">{{ __('Edit') }}</button>
                            <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="removeQueued(@js($queued['id']))">{{ __('Remove') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Questions on their way to the server (a moment, while an earlier send is in flight). --}}
            <div wire:ignore class="flex flex-col gap-5 empty:hidden">
                <template x-for="queued in outbox" :key="queued.id">
                    <div class="flex flex-col items-end gap-1">
                        <div class="fi-chat-queued max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm px-4 py-2.5 text-sm" x-text="queued.text"></div>
                        <div class="flex items-center gap-3 text-xs text-gray-400">
                            <span>{{ __('Queued') }}</span>
                            <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="edit(queued.id)">{{ __('Edit') }}</button>
                            <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="remove(queued.id)">{{ __('Remove') }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div wire:ignore>
            <button
                type="button"
                x-show="busy && ! atBottom"
                x-cloak
                x-transition.opacity
                x-on:click="scrollToBottom(true)"
                class="fi-chat-jump fixed bottom-36 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-gray-900 px-3 py-1.5 text-xs font-medium text-white shadow-lg dark:bg-white dark:text-gray-900"
            >
                <x-filament::icon icon="heroicon-m-arrow-down" class="h-3.5 w-3.5" />
                {{ __('Jump to latest') }}
            </button>
        </div>

        <x-packstub-agents::composer method="send" :model="$model" :attachments="$this->attachmentSettings()" :files="$attachments" :mentions="$this->canMention()" class="sticky bottom-4 shadow-lg">
            @if ($context && $context['meter'])
                {{-- The context ring: how full the history window is, from history.meter_share on. Click for what fills it, what the chat cost so far, Compress now and Continue in a new chat. --}}
                <x-slot:tools>
                    @php($percent = (int) round($context['share'] * 100))
                    <x-filament::dropdown placement="top-end" width="sm" shift>
                        <x-slot name="trigger">
                            <button
                                type="button"
                                class="fi-chat-ring"
                                aria-label="{{ __('Context :percent%', ['percent' => $percent]) }}"
                                x-tooltip="{ content: @js(__('Context :percent%', ['percent' => $percent]).' · '.__('~:used of :budget tokens', ['used' => number_format($context['tokens']), 'budget' => number_format($context['budget'])])), theme: $store.theme, touch: false }"
                            >
                                <svg viewBox="0 0 20 20" aria-hidden="true">
                                    <circle class="fi-chat-ring-track" cx="10" cy="10" r="8" pathLength="100" />
                                    <circle class="fi-chat-ring-fill {{ $context['notice'] ? 'fi-chat-ring-fill-high' : '' }}" cx="10" cy="10" r="8" pathLength="100" stroke-dasharray="{{ $percent }} 100" />
                                </svg>
                            </button>
                        </x-slot>

                        <div class="fi-chat-ring-panel flex flex-col gap-4 p-4 text-xs text-gray-600 dark:text-gray-400">
                            <div>
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('History window') }}</p>
                                <p class="mt-0.5">{{ __('~:used of :budget tokens', ['used' => number_format($context['tokens']), 'budget' => number_format($context['budget'])]) }} · {{ __('estimated') }}</p>
                                @if ($context['turns']['last_tokens_in'] !== null)
                                    <p class="mt-0.5">{{ __('The last question read :tokens tokens.', ['tokens' => number_format($context['turns']['last_tokens_in'])]) }}</p>
                                @endif
                                <dl class="mt-2">
                                    @foreach (\Packstub\Agents\Support\AgentChat::breakdownLabels() as $key => $label)
                                        @if ($context['breakdown'][$key] > 0)
                                            <dt>{{ $label }}</dt>
                                            <dd>{{ number_format($context['breakdown'][$key]) }}</dd>
                                        @endif
                                    @endforeach
                                </dl>
                            </div>

                            @if ($context['turns']['last_tokens_in'] !== null)
                                <div>
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('This chat so far') }}</p>
                                    <dl class="mt-2">
                                        <dt>{{ __('Turns') }}</dt>
                                        <dd>{{ number_format($context['turns']['count']) }}</dd>
                                        <dt>{{ __('Tokens in') }}</dt>
                                        <dd>{{ number_format($context['turns']['tokens_in']) }}</dd>
                                        <dt>{{ __('Tokens out') }}</dt>
                                        <dd>{{ number_format($context['turns']['tokens_out']) }}</dd>
                                        <dt>{{ __('Tool calls') }}</dt>
                                        <dd>{{ number_format($context['turns']['tool_calls']) }}</dd>
                                        <dt>{{ __('Time') }}</dt>
                                        <dd>{{ \Packstub\Agents\Support\AgentChat::duration($context['turns']['duration_ms']) }}</dd>
                                        @if ($context['turns']['cost'] !== null)
                                            <dt>{{ __('Cost') }}</dt>
                                            <dd>{{ \Packstub\Agents\Support\AgentPricing::format($context['turns']['cost']) }}</dd>
                                        @endif
                                    </dl>
                                </div>
                            @endif

                            @if ($context['source'])
                                <p>
                                    {{ __('Continued from') }}
                                    <a href="{{ \Packstub\Agents\Filament\Pages\Chat::getUrl(['conversation' => $context['source']]) }}" class="font-medium text-primary-600 hover:underline">{{ $context['sourceTitle'] ?? __('an earlier chat') }}</a>
                                    — {{ __('the assistant starts from a summary of it.') }}
                                </p>
                            @elseif ($context['summarized'])
                                <p>{{ __('Older messages are summarized for the assistant.') }}</p>
                            @endif

                            @if ($context['notice'])
                                <p class="text-gray-800 dark:text-gray-200">{{ __('This chat is getting long — answers stay sharpest in a new one.') }}</p>
                            @endif

                            <div class="fi-chat-ring-actions">
                                <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-arrows-pointing-in" wire:click="compressNow" x-bind:disabled="busy">{{ __('Compress now') }}</x-filament::button>
                                <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-arrow-right-circle" wire:click="continueInNewChat" x-bind:disabled="busy">{{ __('Continue in a new chat') }}</x-filament::button>
                            </div>
                        </div>
                    </x-filament::dropdown>
                </x-slot>
            @endif
        </x-packstub-agents::composer>
    </div>
</x-filament-panels::page>
