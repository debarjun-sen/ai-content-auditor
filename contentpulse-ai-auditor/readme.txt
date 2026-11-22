=== ContentPulse AI Auditor ===
Contributors: sendeb
Tags: seo, accessibility, content audit, ai, mcp
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Claude-powered agent that audits your content for SEO, accessibility and stale-content issues, then proposes fixes you approve.

== Description ==

ContentPulse AI Auditor scans your posts and pages and gives you a prioritized report in a clean dashboard:

* **SEO** – titles, meta descriptions, thin content, headings, internal links, slugs, featured images.
* **Accessibility** – missing or meaningless alt text, skipped heading levels, empty and vague links, tables without headers, untitled embeds, autoplaying or uncaptioned video (WCAG 2.2).
* **Freshness** – content not updated for a long time, references to past years, time-sensitive wording, broken internal links.
* **Security** – hidden text that tries to manipulate AI tools (prompt injection) is flagged for review.

**Quick scan** runs instantly with built-in rules and needs no API key. **AI audit** lets Claude (Anthropic) verify the findings, prioritize them and write ready-to-approve fixes for meta descriptions, excerpts and image alt text. You can watch every step the agent takes.

Nothing on your site changes until you click **Apply fix**. Every fix shows a before/after preview, can be edited first, saves a revision and can be undone.

Developers and power users can connect Claude Desktop, Claude Code or any MCP client to the built-in MCP endpoint (off by default), and the audit tools are also registered with the Abilities API.

== External services ==

This plugin connects to the **Anthropic API** (https://api.anthropic.com) only when an administrator runs an AI audit or clicks "Test connection", using an API key the site owner provides. For each audited post it sends the title, URL, meta description, excerpt, heading outline, image sources and alt text, link targets and up to a configurable number of characters of plain text. It does not send comments, user accounts, email addresses, private or password-protected posts, or drafts (unless you enable drafts). Quick scans never contact any external service.

* Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy

== Installation ==

1. Install and activate the plugin.
2. Go to **Content Audit** and run a **Quick scan**.
3. For AI audits, add your Anthropic API key under **Content Audit → Settings**, or define `AICA_ANTHROPIC_API_KEY` in wp-config.php.

== Frequently Asked Questions ==

= Will the AI change my posts? =
No. The agent can only read content and record findings. Fixes are applied by you, one at a time, and can be undone.

= How much does an AI audit cost? =
It depends on the model and the number of posts. The dashboard shows live token usage and estimated cost, and every scan has hard limits on turns, tokens and posts that you control in Settings.

= Does it work with Yoast SEO or Rank Math? =
Yes. Approved meta descriptions are written to their fields too, and the plugin stops printing its own meta tag when an SEO plugin is active.

= Why does it need WordPress 6.9? =
The audit tools are registered with the WordPress Abilities API, which ships with WordPress 6.9, and the plugin uses database placeholders that need a modern WordPress.

= What data is stored, and where? =
Scans, findings and the agent's activity log are stored in three tables in your own database. Your API key is stored encrypted in the options table (or read from wp-config.php). Nothing is sent to the plugin authors.

== Screenshots ==

1. Dashboard: site health score, issues by category and top priorities.
2. A live AI audit, narrating each tool call the agent makes.
3. Findings with a before/after preview and an editable fix.
4. Agent activity log showing the exact input of every tool call.
5. Connect tab for Claude Desktop, Claude Code and other MCP clients.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
