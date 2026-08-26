# packstub/filament-agents

Governed AI-agent access for Filament v5 panels: exposes panel resources as MCP tools with capability grants and previews. Dogfooded in the store (`../../store`, mounted at `/mcp/admin`).

## Commands

```bash
composer test               # Pest suite
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/` package code, `config/`, `database/`, `tests/`.

## Conventions

- PHP 8.3+, Pint, Pest; every change needs a test.
- Any change to tool exposure or grants must keep the store's MCP integration passing — run the store suite after package changes.
