@php
    use Packstub\Agents\Facades\Agents;
    use Packstub\Agents\Filament\Pages\Chat;
    use Packstub\Agents\Filament\Pages\Chats;
    use Packstub\Agents\Support\AgentModels;
    use Laravel\Ai\Models\Conversation;

    $show = auth()->check() && Agents::inPanel() && AgentModels::enabled();
    $chats = $show
        ? Conversation::query()->where('participant_type', auth()->user()->getMorphClass())->where('participant_id', auth()->id())->latest('updated_at')->limit(8)->get()
        : collect();
    $current = request()->route('conversation');
@endphp
@if ($show)
    <div class="fi-sidebar-chats mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
        <div class="flex items-center justify-between px-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Chats') }}</span>
            <a href="{{ Chat::getUrl() }}" class="rounded p-1 text-gray-400 hover:text-primary-600" title="{{ __('New chat') }}">
                <x-filament::icon icon="heroicon-m-plus" class="h-4 w-4" />
            </a>
        </div>
        <ul class="mt-2 space-y-0.5">
            @forelse ($chats as $chat)
                <li>
                    <a href="{{ Chat::getUrl(['conversation' => $chat->id]) }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm {{ $current === $chat->id ? 'bg-gray-100 text-primary-600 dark:bg-white/5' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5' }}">
                        <x-filament::icon icon="heroicon-o-chat-bubble-left" class="h-4 w-4 shrink-0 text-gray-400" />
                        <span class="truncate">{{ $chat->title }}</span>
                    </a>
                </li>
            @empty
                <li class="px-2 py-1.5 text-xs text-gray-400">{{ __('Nothing asked yet.') }}</li>
            @endforelse
            @if ($chats->isNotEmpty())
                <li>
                    <a href="{{ Chats::getUrl() }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5">
                        <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="h-4 w-4 shrink-0 text-gray-400" />
                        <span>{{ __('All chats') }}</span>
                    </a>
                </li>
            @endif
        </ul>
    </div>
@endif
