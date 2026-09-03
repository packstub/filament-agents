# MCP clients

The same tools the chat uses are served over HTTP as an MCP server, so Claude Code, Claude Desktop, Cursor or any client that speaks the Model Context Protocol can work inside the panel as the person who minted the token, in their workspace, with their role.

## The Agent access page

`AgentsPlugin` registers an **Agent access** page (`/agent-access`) whenever `agentAccess()` is on (the default). Gate it with an ability and put it in a navigation group:

```php
AgentsPlugin::make()->agentAccess(ability: 'setup.view', group: fn () => __('Setup'))
```

The page mints Sanctum personal access tokens for the signed-in person:

- a label ("Claude Code on my laptop");
- abilities: **Read** (look things up, reports) and **Write** (change data through the tools, still limited by the person's role);
- in a panel with tenancy, the token also carries `tenant:{slug}` so it only works on that workspace's URL.

The plain token is shown once, together with the ready-made connection snippets:

```bash
claude mcp add --transport http acme https://acme.test/mcp --header "Authorization: Bearer 3|…"
```

```json
{ "mcpServers": { "acme": { "type": "http", "url": "https://acme.test/mcp", "headers": { "Authorization": "Bearer 3|…" } } } }
```

Below, a table lists the person's tokens (label, abilities, last used, created) with a **Revoke** action. Tokens are regular Sanctum tokens, so `$user->tokens()` and Sanctum's pruning work as usual.

## The endpoint

`POST /mcp` by default (`packstub-agents.mcp.path`), registered with `Mcp::web()` once every panel is known. The middleware stack:

```php
'middleware' => ['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class],
```

`AuthenticateAgent` puts the request into the same shape as a panel request: the assistant's panel is made current, the person's locale is applied, and, when the path carries `{tenant}`, the workspace is resolved and set (see [Tenancy](tenancy.md)). Every tool then behaves exactly as it does in the chat.

Set `AGENT_MCP_ENABLED=false` to remove the route and the Agent access page.

## What a client sees

- `tools/list` returns the tools the person's role allows (each tool's `shouldRegister()` checks its ability), in the order of the server's `$tools`, with the server's name and instructions.
- A **read** token runs read-only tools; calling a write tool returns a tool error ("This access token is read-only.").
- A **write** token runs write tools directly with the token holder's role. There is no approval step over MCP: the client is the agent the person chose to trust, and the server's instructions tell it to read the record first when in doubt.
- A tool the role does not allow is not listed; a direct call returns the refusal ("Your role (Viewer) is not allowed to do this.").

## Testing the endpoint

```php
$token = $user->createToken('desk', ['read', 'write'])->plainTextToken;

postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
    'Authorization' => 'Bearer '.$token,
    'Accept' => 'application/json, text/event-stream',
    'MCP-Protocol-Version' => '2025-06-18',
])->assertOk()->assertJsonPath('result.tools.0.name', 'search-orders');
```

See [Testing](testing.md) for the full example.
