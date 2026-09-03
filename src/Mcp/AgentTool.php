<?php

namespace Packstub\Agents\Mcp;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Facades\Agents;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * One capability of the product. Every tool is a laravel/mcp tool: the MCP
 * server lists it to external agents, and the in-panel chat calls the very
 * same class through laravel/ai's McpServerTool bridge.
 *
 * Authorization is the panel's: a tool is only registered (and only runs)
 * when the current person may $ability — the same strings that gate the
 * resources and actions, so the agent can never do more than its user.
 * Domain errors (RuntimeException from the services) are handed back to the
 * model as tool errors, never thrown at the user.
 */
abstract class AgentTool extends Tool
{
    /** The ability required to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = null;

    public function shouldRegister(): bool
    {
        return Agents::allows($this->ability);
    }

    public function handle(Request $request): Response
    {
        if (! Agents::allows($this->ability)) {
            return Response::error(self::refusal());
        }

        // An agent access token may be read-only; the in-panel chat (session auth) has no token and relies on approvals instead.
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token && ! $this->isReadOnly() && ! $user->tokenCan('write')) {
            return Response::error(__('This access token is read-only.'));
        }

        try {
            return Response::json($this->run($request));
        } catch (ValidationException $e) {
            return Response::error(__('Invalid arguments: :errors', ['errors' => collect($e->errors())->flatten()->join(' ')]));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error(__('The action failed: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Execute the tool and return the data the model gets to see (encoded as JSON).
     *
     * @return array<string, mixed>
     */
    abstract protected function run(Request $request): array;

    public function isReadOnly(): bool
    {
        return self::hasReadOnlyAnnotation($this);
    }

    public static function hasReadOnlyAnnotation(object $tool): bool
    {
        return (new ReflectionClass($tool))->getAttributes(IsReadOnly::class) !== [];
    }

    /** The message a person gets when their role may not run a tool. */
    public static function refusal(): string
    {
        $role = Agents::roleLabel();

        return $role
            ? __('Your role (:role) is not allowed to do this.', ['role' => $role])
            : __('You are not allowed to do this.');
    }

    /** Clamp a requested page size. */
    protected function limit(Request $request, int $default = 20, int $max = 50): int
    {
        return max(1, min($max, (int) ($request->get('limit') ?: $default)));
    }
}
