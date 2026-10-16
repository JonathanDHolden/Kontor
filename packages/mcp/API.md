# Kontor MCP API

`ProcessWire\\KontorMCP` declares `mcpProvider => true` and implements `mcpProviderInfo()` and `mcpTools()` for MCP Server 1.0.

Version 1.0.1 also opts into PocketWire with `pocketWireProviderInfo()`, `pocketWireManifest(User $actor)`, and `pocketWireRead(...)`. That SDK surface is read-only and requires the current actor to hold `kontor-access`. Resource metadata and records are filtered by an explicit resource-to-view-permission allowlist; search requires explicit entity types and checks each type's component view permission. Unknown and dynamic resources fail closed for non-superusers until their permission mapping becomes a documented contract. Reads use the same organization-scoped bridge and live API resource schemas. PocketWire exposes no mutation, validation, settings, secret, file, shell, or external-call action.

The provider publishes 13 tools covering readiness, components, live resource schemas, bounded search, resource list/get/validate/create/update/archive, and settings export/preview/apply.

JSON record attributes, filters, sort instructions, sparse fields and settings profiles are passed as bounded JSON strings. The bridge decodes them only after MCP Server validates the closed outer schema, then validates resource names, capabilities, fields and value types against the live `ApiResourceSchema`.

No component-specific names are hardcoded. Install an optional component that registers an `ApiResourceInterface` with `KontorAPI::resourceRegistry()` and its resource immediately appears in `kontor_resources` and the generic record tools.
