# Scope & Architecture

## Goal

A WordPress plugin that runs a **Claude-powered auditing agent** over a site's
content and reports **SEO**, **accessibility**, and **stale-content** issues in a
clean admin dashboard that demos well. The agent only *reads* site content and
*proposes* fixes; a human approves every change.

## Deliverables

| # | Milestone | What ships |
|---|-----------|------------|
| 1 | Scope & companion plugin | This document; `ai-content-auditor-demo` companion plugin that seeds a site with realistic, intentionally flawed content (incl. a prompt-injection trap) and removes it again. |
| 2 | MCP server & core tools | One tool registry used by three surfaces: the in-plugin Claude agent loop, a built-in MCP endpoint (`/wp-json/aica/v1/mcp`, JSON-RPC 2.0 / Streamable HTTP), and WordPress Abilities API registrations (WP 6.9+, picked up by the official MCP Adapter). |
| 3 | Safety & tests | Read-only agent, human-approved allowlisted fixes with undo, untrusted-content wrapping + prompt-injection detection, schema validation of every tool input, per-scan token/iteration/post budgets, single-scan lock, capability + nonce checks, encrypted API key. PHPUnit unit + integration suites. |
| 4 | Demo, README, publishing | Playground blueprint (one-click demo), wp-env config, demo script, README, `readme.txt`, zip build script, CI workflow. |

## Non-goals (v1)

- Fetching external URLs (no outbound link checking — keeps scans fast and private).
- Autonomous edits. The agent never writes to posts; only `record_finding` writes, and only to the plugin's own table.
- Front-end rendering audits (contrast, focus order). We audit stored content markup.

## Architecture

```
            ┌─────────────── Admin UI (vanilla JS, REST) ───────────────┐
            │ Dashboard · Findings · Activity · Connect (MCP) · Settings │
            └───────────────┬───────────────────────────────────────────┘
                            │ aica/v1 REST (cookie + nonce, capability)
         ┌──────────────────┼──────────────────────┐
         ▼                  ▼                      ▼
   Audit_Agent         Quick_Scanner          Fix_Applier (human-approved)
   (step-wise Claude    (rules only,            allowlist: meta_description,
    tool-use loop)       no API key)            excerpt, image_alt · undo
         │                  │
         └──────┬───────────┘
                ▼
          Tool_Registry  ◄──── MCP_Server (/aica/v1/mcp)  ◄── Claude Desktop / Code
                │        ◄──── Abilities (wp_register_ability) ◄── MCP Adapter
                ▼
   Content_Extractor → SEO / Accessibility / Freshness analyzers, Injection_Guard
                │
                ▼
     Repository (aica_scans, aica_findings, aica_events)
```

### Why the agent loop is step-wise

PHP requests time out. Each `POST /scans/{id}/step` performs exactly one Claude
round-trip plus the tool calls it asked for, persists the (append-only)
conversation, and returns new activity events. The dashboard calls `step` in a
loop and renders a live timeline; WP-Cron and WP-CLI can drive the same loop
headlessly.

### Claude API usage

- Model `claude-opus-5-5` by default (Sonnet 5.5 / Haiku 5.5 selectable).
- Adaptive thinking (default for these models), `output_config.effort` (default `medium`).
- Prompt caching: `cache_control` on the system block (caches tools + system) plus
  top-level automatic caching for the growing conversation.
- Server-side refusal fallback (`fallbacks: "default"`) where supported.
- Assistant `content` (incl. thinking blocks) is replayed verbatim; history is append-only.
- Calls go through the WordPress HTTP API (`wp_remote_post`) rather than the
  Composer SDK, so the plugin has no runtime dependencies, respects WP proxy
  settings, and can't clash with other plugins' vendored Guzzle versions.
