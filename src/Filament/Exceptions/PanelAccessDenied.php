<?php

namespace Packstub\Agents\Filament\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;

/**
 * A panel was about to be entered for a person its canAccessPanel() refuses
 * (suspended after minting a token or asking a question). Thrown by
 * FilamentContext::enter() on every path that acts on the panel outside a
 * page request — the MCP request, the queued turn, AgentRun, the email
 * channel — the way Filament's Authenticate middleware refuses the page. A
 * WorkspaceAccessDenied, so the engine handles it as it does the membership
 * refusal: the turn ends failed with the line, the mail is dropped, AgentRun
 * lets it through; the MCP request is answered 403 with the line.
 */
class PanelAccessDenied extends WorkspaceAccessDenied
{
    public static function make(): static
    {
        return new static(__('You do not have access to this panel.'));
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson() ? response()->json(['error' => $this->getMessage()], 403) : null;
    }
}
