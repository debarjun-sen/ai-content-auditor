# ContentPulse AI Auditor for WordPress

A Claude-powered agent that audits a WordPress site for **SEO**, **accessibility** and **stale content**, then proposes fixes that a human approves. It ships with a clean admin dashboard where you can watch the agent work live, an **MCP server** so Claude Desktop and Claude Code can use the same audit tools, and a **companion demo plugin** that fills a site with realistic, deliberately flawed content.

> **Safe by design:** the agent can *read* content and *record findings*. It cannot edit posts. Every change goes through a human-approved, allowlisted, undoable fix.

| Dashboard | Live agent run | Findings with approve-to-apply fixes |
|---|---|---|
| Health score, category scores, top priorities, history | Every tool call Claude makes, streaming as it happens | Before/after preview, editable suggestion, one-click undo |

---

## Contents

- [What it checks](#what-it-checks)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [MCP server](#mcp-server)
- [Safety model](#safety-model)
- [Settings](#settings)
- [WP-CLI](#wp-cli)
- [Development & tests](#development--tests)
- [Publishing](#publishing)
- [Extending](#extending)

## What it checks

| Category | Checks (deterministic tools the agent uses as evidence) |
|---|---|
| **SEO** | Title length, missing/odd-length meta description, thin content, body H1s, long text without subheadings, no internal links, long slugs, missing featured image |
| **Accessibility** (WCAG 2.2) | Missing or file-name alt text (1.1.1), skipped heading levels & empty headings (1.3.1, 2.4.6), empty links & vague link text (2.4.4), tables without header cells (1.3.1), untitled iframes (4.1.2), autoplaying video (2.2.2), videos without captions (1.2.2) |
| **Freshness** | Not updated in N days, past years in title/body, time-sensitive wording on old posts, broken internal links |
| **Security** | Prompt-injection text aimed at AI systems hidden in content (flagged automatically, never obeyed) |

Claude verifies these signals, drops false positives, adjusts severity, catches issues the rules miss, and writes **ready-to-approve fixes** for meta descriptions, excerpts and image alt text.

## Quick start

### Option A — one-click Playground demo (no install)

**[Open the live demo in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/debarjun-sen/ai-content-auditor/main/blueprint.json)** (no install needed).

The blueprint installs both plugins, seeds demo content and runs a quick scan, so the dashboard is populated on arrival. Add an API key under **Content Audit → Settings** to run the AI audit.

### Option B — local Playground from this folder

```powershell
npm run playground
```

Mounts both plugin folders into a local WordPress Playground using [`blueprint.local.json`](blueprint.local.json).

### Option C — wp-env (Docker)

```powershell
npm install
```

```powershell
npm run env:start
```

```powershell
npm run demo:seed
```

Open <http://localhost:8888/wp-admin/admin.php?page=contentpulse-ai-auditor> (user `admin` / password `password`).

### Option D — any WordPress site

Upload `dist/contentpulse-ai-auditor.zip` (build with `npm run build`) via **Plugins → Add New → Upload**. Optionally add the demo plugin too.

### Add your Claude API key

Either paste it in **Content Audit → Settings** (stored encrypted with AES-256-GCM, never sent to the browser) or, preferably, keep it out of the database entirely:

```php
// wp-config.php
define( 'AICA_ANTHROPIC_API_KEY', 'sk-ant-...' );
```

The `ANTHROPIC_API_KEY` environment variable also works. Click **Test connection** to verify.

## How it works

```
Admin UI ──REST──► Audit_Agent (one Claude turn per request) ──► Tool_Registry ──► Analyzers
   ▲                    │                                            ▲      │
   └── live timeline ◄──┘ events table                MCP_Server ───┘      └─► record_finding → findings table
                                                       Abilities API ──┘
```

- **One tool registry, three surfaces.** The same tool definitions (JSON Schema, annotations, callbacks) power the built-in agent, the MCP endpoint, and WordPress Abilities (WP 6.9+).
- **Step-wise agent loop.** PHP requests time out, so each `POST /aica/v1/scans/{id}/step` performs exactly one Claude call plus the tool calls it requested, then persists the append-only conversation. The dashboard calls `step` in a loop and renders the activity timeline; WP-Cron continues an unattended scan; WP-CLI runs it to completion.
- **Claude API usage.** Model `claude-opus-5-5` by default (Sonnet 5.5 / Haiku 5.5 selectable), adaptive thinking (the model default), `output_config.effort` (default `medium`), prompt caching on the tools+system prefix plus automatic caching of the growing conversation, server-side refusal fallback (`fallbacks: "default"`), and verbatim replay of assistant content including thinking blocks. Parallel tool calls are answered in a single user message; tool failures are returned as `is_error` results.
- **No runtime dependencies.** Requests go through `wp_remote_post()` rather than the Composer SDK: plugins can't rely on Composer at runtime, vendored HTTP stacks collide between plugins, and the WordPress HTTP API respects the site's proxy/SSL configuration.
- **Quick scan** runs the same analyzers without the API (free, instant) and even suggests meta descriptions from the lead text.

### Tools

| Tool | Access | Purpose |
|---|---|---|
| `get_site_overview` | read-only | Site info, content counts, SEO plugin, budgets |
| `list_content` | read-only | Paginated list of auditable posts/pages |
| `get_content` | read-only | Structured view of one post; text wrapped in `<untrusted_content>` |
| `check_seo` | read-only | Deterministic SEO checks |
| `check_accessibility` | read-only | WCAG-oriented markup checks |
| `check_freshness` | read-only | Staleness checks incl. broken internal links |
| `find_stale_content` | read-only | Oldest content past the threshold |
| `record_finding` | writes findings only | Adds an issue (and optional suggested fix) to the report |
| `list_findings` | read-only | Reads the report |
| `apply_fix` | **MCP only, off by default** | Applies an existing finding's fix (never offered to the built-in agent) |

## MCP server

Enable **Settings → MCP server → Enable MCP endpoint**, create an **Application Password** for an administrator, then:

```powershell
claude mcp add --transport http content-auditor https://your-site.example/wp-json/aica/v1/mcp --header "Authorization: Basic <base64 user:app-password>"
```

The **Connect (MCP)** tab generates ready-to-paste snippets for Claude Code and Claude Desktop (the Base64 header is computed in your browser and never sent anywhere). The endpoint speaks JSON-RPC 2.0 over Streamable HTTP (`initialize`, `ping`, `tools/list`, `tools/call`; protocol versions 2025-11-25, 2025-06-18, 2025-03-26). Findings recorded by external agents appear in the dashboard tagged **MCP agent**.

On WordPress 6.9+ the tools are also registered as Abilities (`contentpulse-ai-auditor/check-seo`, …) with `meta.mcp.public`, so the official WordPress MCP Adapter can serve them too.

## Safety model

| Risk | Mitigation |
|---|---|
| Agent edits content | The built-in agent has no write tool. `record_finding` writes only to the plugin's own table. `apply_fix` exists only for MCP, is disabled by default, and is hidden from `tools/list` until enabled. |
| Bad or malicious fixes | Fixes are limited to an allowlist (`meta_description`, `excerpt`, `image_alt`), stripped of HTML, length-capped, previewed as before/after, editable, and applied only on click. Content edits create a revision; every fix has one-click undo, which refuses to overwrite later edits. |
| Prompt injection in site content | All site content is wrapped in an `<untrusted_content>` envelope (breakout tags neutralized) and the system prompt treats it as data. A detector scans raw markup, comments and hidden text for instructions aimed at AI systems and records a **Security** finding so a human reviews it. |
| Malformed tool input | Every call — from Claude, MCP or Abilities — is validated against the tool's JSON Schema (types, enums, ranges, lengths, `additionalProperties: false`) before execution. |
| Runaway cost | Hard per-scan limits on agent turns, tokens and posts read. Near the limit the agent is told to wrap up; past it the scan stops and keeps its findings. Live token and cost meter. One scan at a time (global lock) plus a per-scan step mutex. |
| Data exposure | Private and password-protected posts are never read; drafts only if enabled. Per-post text sent to the API is truncated (configurable). No comments, users or emails are sent. |
| Access control | All REST routes require `manage_options` (filterable via `aica_capability`); cookie auth uses the REST nonce; applying a fix also requires `edit_post` on that post. |
| Secret handling | Key via constant/env (recommended) or AES-256-GCM-encrypted option keyed from site salts; only a masked form is ever rendered. |

## Settings

| Setting | Default |
|---|---|
| Model / effort / refusal fallback | Claude Opus 5.5 / medium / on |
| Content types / include drafts / stale after | posts + pages / off / 365 days |
| Max posts per scan / agent turns / token budget | 25 / 30 / 600,000 |
| Characters of content sent per post | 6,000 |
| Output approved meta descriptions in `<head>` (when no SEO plugin) | on |
| MCP endpoint / allow MCP clients to apply fixes | off / off |
| Delete data on uninstall | off |

Meta descriptions are written to `_aica_meta_description` and, when active, Yoast SEO or Rank Math's field too.

## WP-CLI

```powershell
wp aica audit --mode=quick
```

```powershell
wp aica audit --mode=ai --max-posts=10
```

```powershell
wp aica findings --status=open --format=table
```

```powershell
wp aica score
```

```powershell
wp aica tool check_accessibility '{"post_id": 12}'
```

Demo companion: `wp aica-demo seed` and `wp aica-demo reset`.

## Development & tests

```
contentpulse-ai-auditor/            ← the plugin
  includes/analyzers/          ← pure PHP analyzers (unit-tested without WordPress)
  includes/safety/             ← injection guard, schema validator, fix applier
  includes/tools/              ← tool registry (single source of truth)
  includes/agent/              ← Claude client, step-wise agent, quick scanner
  includes/mcp/                ← MCP server + Abilities API registration
  assets/                      ← admin UI (vanilla JS + CSS, no build step)
  tests/unit/                  ← PHPUnit, no WordPress needed
  tests/integration/           ← WP test suite: tools, agent loop (scripted API), fixes, REST, MCP
contentpulse-ai-auditor-demo/       ← companion plugin with flawed demo content
docs/                          ← scope/architecture and demo script
blueprint.json                 ← Playground one-click demo
```

**Unit tests** (any machine with PHP 7.4+ and Composer):

```powershell
cd contentpulse-ai-auditor; composer install; composer test:unit
```

**Integration tests** (Docker + wp-env):

```powershell
npm run env:start; npm run composer:install; npm run test:integration
```

The integration suite drives the full agent loop against scripted Claude responses (via `pre_http_request`) and asserts the request shape (caching, fallback header, verbatim thinking replay, `{}` tool inputs, parallel results in one message), budget behavior, refusal and retry handling, scope rules, fix apply/undo/conflict, REST permissions and the MCP protocol.

## Publishing

1. `npm run build` → `dist/contentpulse-ai-auditor.zip` and `dist/contentpulse-ai-auditor-demo.zip`.
2. Create a GitHub release and attach the zips; CI (`.github/workflows/ci.yml`) runs unit tests on PHP 7.4/8.1/8.3, integration tests in wp-env, linting, and uploads the zips as an artifact.
3. Share the Playground link above; it always installs the latest `main` branch.
4. For WordPress.org: `contentpulse-ai-auditor/readme.txt` is ready; run Plugin Check before submitting. Note that the plugin calls an external service (Anthropic API) only when an AI audit is run with a key the site owner provides — this is disclosed in the readme as the directory guidelines require.

## Extending

| Hook | Use |
|---|---|
| `aica_tools` (filter) | Add or change tools (all surfaces pick them up) |
| `aica_system_prompt` (filter) | Adjust the agent's instructions |
| `aica_fix_fields` (filter) | Change the fix allowlist / length caps |
| `aica_apply_custom_fix` (filter) | Implement a custom fix field |
| `aica_capability` (filter) | Change who can run audits |
| `aica_claude_request_args` (filter) | Adjust HTTP args (timeouts, proxies) |
| `aica_fix_applied`, `aica_scan_finished` (actions) | Notifications, logging, Slack, etc. |

## License

GPL-2.0-or-later.
