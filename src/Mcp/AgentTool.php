<?php

namespace Packstub\Agents\Mcp;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\TransientToken;
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
 * An agent access token narrows that further: a read token never sees a
 * write tool, and a token scoped to some tools ("tool:{name}" abilities)
 * sees only those. Both checks run on the tool list and again on the call.
 * Domain errors (RuntimeException from the services) are handed back to the
 * model as tool errors, never thrown at the user.
 */
abstract class AgentTool extends Tool
{
    /** The ability required to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = null;

    public function shouldRegister(): bool
    {
        return Agents::allows($this->ability) && $this->tokenRefusal() === null;
    }

    public function handle(Request $request): Response
    {
        if (! Agents::allows($this->ability)) {
            return Response::error(self::refusal());
        }

        if ($refusal = $this->tokenRefusal()) {
            return Response::error($refusal);
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

    /**
     * Why the current agent access token may not run this tool, or null when
     * it may. The in-panel chat (session auth) has no token and relies on
     * approvals instead; Sanctum's transient token for a session stands for
     * "no token" as well.
     */
    public function tokenRefusal(): ?string
    {
        $token = self::accessToken();

        if (! $token) {
            return null;
        }

        if (! $this->isReadOnly() && ! $token->can('write')) {
            return __('This access token is read-only.');
        }

        if (self::tokenIsScoped($token) && ! $token->can('tool:'.$this->name())) {
            return __('This access token does not include :tool.', ['tool' => $this->name()]);
        }

        return null;
    }

    /** The personal access token the request was authenticated with, if any. */
    public static function accessToken(): ?HasAbilities
    {
        $user = auth()->user();
        $token = $user && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof HasAbilities && ! $token instanceof TransientToken ? $token : null;
    }

    /**
     * The tool names a token was limited to ("tool:{name}" abilities); empty = every tool the role allows.
     *
     * @return list<string>
     */
    public static function tokenTools(HasAbilities $token): array
    {
        $abilities = (array) ($token->abilities ?? []);

        return array_values(array_map(fn (string $a) => substr($a, 5), array_filter($abilities, fn ($a) => str_starts_with((string) $a, 'tool:'))));
    }

    public static function tokenIsScoped(HasAbilities $token): bool
    {
        return self::tokenTools($token) !== [];
    }

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
