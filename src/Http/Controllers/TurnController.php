<?php

namespace Packstub\Agents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Support\AgentTurns;

/**
 * What the chat page polls while an answer is produced (and, slowly, while
 * it is open): the running turn's answer so far, rendered, and a version
 * stamp that changes whenever the conversation did — so a page that was
 * reopened, reloaded or opened in a second tab shows the same thing.
 * Registered on the panel's authenticated (tenant) routes by AgentsPlugin.
 */
class TurnController
{
    public function __invoke(Request $request, AgentTurns $turns): JsonResponse
    {
        // Read by name: in a panel with tenancy the first route parameter is the tenant.
        $conversation = (string) $request->route('conversation');

        $updated = Conversation::query()
            ->whereKey($conversation)
            ->where('participant_type', auth()->user()?->getMorphClass())
            ->where('participant_id', auth()->id())
            ->value('updated_at');

        abort_if($updated === null, 404);

        $turns->reconcile($conversation);
        $active = $turns->active($conversation);
        $latest = $turns->latest($conversation);

        return response()
            ->json([
                'active' => $active ? [
                    'id' => $active->id,
                    'status' => $active->status,
                    'statusText' => $active->status_text ?? __('Thinking…'),
                    'html' => filled($active->text) ? Chat::markdown((string) $active->text) : '',
                ] : null,
                'version' => md5(json_encode([(string) $updated, $latest?->id, $latest?->status, $turns->queued($conversation)->pluck('id')->all()])),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
