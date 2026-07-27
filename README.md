# Packstub Agents

Governed AI-agent access for Filament v5 panels. Exposes your application to AI agents (Claude Code, Claude Desktop, Cursor, …) over [MCP](https://modelcontextprotocol.io) — with the governance layer an operator needs before letting a non-deterministic process near production data:

- **Explicit opt-in tools** — nothing is exposed until you register a tool class and grant it to a token.
- **Capability grants** — every agent token carries per-tool grants (`read` / `write` / `destructive`), re-evaluated on every call. Ungranted tools are invisible *and* uninvokable.
- **Write safety** — write and destructive tools never mutate directly. They produce a field-level diff and a pending approval; an admin reviews and applies it from the Filament control plane. Previous values are recorded for every applied change.
- **Append-only audit trail** — every decision (allow *and* deny), every approval, every applied change, with redacted arguments.
- **Filament control plane** — Agent Tokens (one-time secret display, revocation), Pending Approvals (diff review, approve/reject), Audit Log.

## Requirements

- PHP 8.4+, Laravel 13, Filament v5, `laravel/mcp` ^0.9

## Installation

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install
```

Register the plugin in your panel provider:

```php
->plugin(\Packstub\Agents\AgentsPlugin::make())
```

Expose tools in `config/packstub-agents.php`:

```php
'route' => ['path' => '/mcp/admin'],
'tools' => [
    \App\Mcp\Tools\ListPackages::class,
    \App\Mcp\Tools\UpdatePackageStorefront::class,
],
```

Create a token and hand it to an agent:

```bash
php artisan packstub:agents:create-token "Claude" \
    --grant=list-packages:read \
    --grant=update-package-storefront:write
```

The agent connects to `https://your-app.test/mcp/admin` with the printed bearer token.

## Writing tools

Read tools extend `Packstub\Agents\Mcp\GovernedTool` and implement `handle()` (call `$this->authorizeOrFail($gate, $request)` first). Write/destructive tools extend `Packstub\Agents\Mcp\ApprovableTool` and implement `proposal()` (the diff to review) and `apply()` (executed only when an admin approves).

## License

Proprietary — see [LICENSE.md](LICENSE.md). Sold via [packstub.dev](https://packstub.dev).
