<x-filament-panels::page>
    {{-- The chat itself is the engine's (packstub-agents-chat::chat): an Alpine component over plain HTTP that keeps
         itself up to date, so this page never re-renders it (wire:ignore). Its notices become Filament notifications. --}}
    <div
        wire:ignore
        x-data
        x-on:agents-notify.window="
            const notice = $event.detail
            const notification = new FilamentNotification().title(notice.title)
            if (notice.body) notification.body(notice.body)
            if (notice.persistent) notification.persistent()
            notification.status(notice.level ?? 'info').send()
        "
    >
        {!! $this->chatHtml() !!}
    </div>
</x-filament-panels::page>
