# Kontor MCP

Kontor MCP is the optional provider component connecting Kontor to [MCP Server](https://github.com/mxmsmnv/McpServer). MCP Server owns authenticated Streamable HTTP transport, client identities, scopes, rate limits and audit evidence; this component owns the Kontor operations exposed through that boundary.

Version 1.0.1 additionally provides an opt-in, read-only PocketWire SDK surface. A matching ProcessWire actor with `kontor-access` can inspect bounded component/resource metadata, search, and read organization-scoped API resources only when the relevant component view permission is explicitly mapped. Unknown or dynamic resources fail closed for non-superusers. PocketWire receives no write actions, settings profiles, secrets, raw files, or external integrations.

## Access model

An MCP Server client with the `admin` scope can call every tool published by this provider. Lower scopes receive a strict subset:

- `read`: status, component/resource discovery, bounded search and record reads, mutation validation, settings export and import preview;
- `draft`: validated, idempotent record create and update operations, plus all read tools;
- `admin`: reviewed archive and settings-apply operations, plus every lower scope.

“Full access” remains bounded: there is no PHP, shell, SQL, filesystem, module installation, permission management, secret retrieval or raw-file tool. Every input schema is closed and bounded, writes use stable identifiers and idempotency keys, and archive/settings apply require explicit confirmation phrases.

## Optional components

The provider reads `KontorAPI::resourceRegistry()`. A component contributes CRUD only when it is installed and has registered its own `ApiResourceInterface`; uninstalling an optional component automatically removes its resources from MCP discovery. `KontorSearch` and `KontorSettings` add search and settings migration when installed.

## Installation

1. Install `McpServer`, `Kontor`, `KontorAPI` and this `KontorMCP` module.
2. Open **Setup → MCP Server → Providers** and review the Kontor tools.
3. Keep the endpoint disabled until host, Origin, environment and namespace settings are reviewed.
4. Create a separate client identity. Use `admin` only for a runtime that genuinely needs every published tool.
5. Copy the bearer token once to the client’s secret environment. Never store it in Git or Kontor settings.
