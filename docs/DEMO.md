# 5-minute demo script

A walkthrough that shows every feature in a logical order. Works in Playground, wp-env or any test site.

## Setup (before the audience arrives)

1. Activate **ContentPulse AI Auditor** and **ContentPulse AI Auditor – Demo Content**.
2. **Tools → AI Auditor Demo → Create demo content** (or `wp aica-demo seed`). This creates 8 posts/pages:

   | Item | Planted issues |
   |---|---|
   | Best SEO Tools for *(5 years ago)* | 4+ years stale, past year in title, “this year / currently”, vague “click here / Read more”, broken internal link, thin, no meta |
   | Meet Our Team | image with no alt, alt = `IMG_2041.jpg`, H2 → H4 skip, table without headers |
   | Hi | 2-char title, ~30 words, no meta, no featured image |
   | The Ultimate Complete… Content Marketing Strategy | 100+ char title, body H1, no internal links, 100-char slug |
   | Join Our Upcoming Webinar: WordPress 6.4 Launch Party | ~3-year-old event, “coming soon / register now”, untitled YouTube iframe, autoplay video without captions |
   | Product Update: New Dashboard Features | **hidden prompt injection** in an HTML comment and an off-screen paragraph |
   | Contact Us | linked image with empty alt (empty link), untitled map iframe, broken link |
   | Accessibility Statement… | the control — should come back nearly clean |

3. Add your API key in **Content Audit → Settings** and click **Test connection**.
4. Optional: enable the MCP endpoint and connect Claude Code (see the Connect tab).

## The demo

1. **Empty state → Quick scan** (≈2 s, no API cost). The score ring animates in; category cards show SEO / Accessibility / Freshness / Security. Point out the **Security** card: the injection text was caught by rules alone.
2. **Run AI audit.** The live card shows Claude's turns, posts read, findings, tokens and cost climbing. The timeline narrates each tool call: *“Checking accessibility of ‘Meet Our Team’”*, *“Recording finding…”*. Note the red **safety** event when it reads the Product Update page — the injection was flagged and ignored.
3. **Agent activity tab.** Expand any tool call to show its exact JSON input — full transparency into what the agent did.
4. **Findings tab.** Filter to *Accessibility*. Open “Image has no alt attribute” on *Meet Our Team*: Claude's suggested alt text sits next to the current (empty) value. **Edit the suggestion**, click **Apply fix**, confirm. Toast with **Undo**; the health score ticks up.
5. Open the **meta description** fix on *Hi* or *Best SEO Tools* → apply → view the post's source to see the `<meta name="description">` tag.
6. **Undo** a fix to show it's fully reversible (and that undo refuses if the post was edited since).
7. **Connect (MCP) tab.** Show the tools table with read-only / writes-findings / disabled badges, then in Claude Code: *“Use content-auditor to find stale posts on my site and record findings.”* Refresh Findings — they appear tagged **MCP agent**.
8. **Settings → Safety & budgets.** Close on the guardrails: turn/token/post caps, drafts excluded by default, fixes allowlisted, MCP writes off by default.

## Reset

**Tools → AI Auditor Demo → Remove demo content** (or `wp aica-demo reset`). Findings stay in history; delete the plugin with “Delete data on uninstall” enabled to remove everything.
