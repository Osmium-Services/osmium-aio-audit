# Osmium AIO Audit

Audits on demand how well AI answer engines (ChatGPT, Claude, Perplexity, Google's AI features) can find and cite the site, and lists the fixes from Critical down to Low, with a 0-100 score to track between runs.

Admin: Service Settings > AIO Audit (report, with Download report: the run as a Markdown file to hand to an assistant), Runs (history and what changed), Settings (page limit, delay, crawl address). Read-only MCP tool: `aio_audit_latest`.

## Checks

| Check | Priority | Raised when |
|---|---|---|
| `ai_search_bot_blocked` | High | Live only. robots.txt keeps OAI-SearchBot, Claude-SearchBot or PerplexityBot off the home page. Blocking a search crawler removes the site from that engine's answers. One finding per bot. |
| `ai_user_bot_blocked` | Medium | Live only. Same, for the fetchers that read a page when a person asks an assistant about it: ChatGPT-User, Claude-User, Perplexity-User. OpenAI says robots.txt may not apply to ChatGPT-User, so the rule may be ignored. |
| `ai_training_bot_blocked` | Low | Live only. Same, for the training crawlers GPTBot, ClaudeBot, Google-Extended and CCBot. Blocking them is a legitimate policy choice and does not reduce citations. |
| `org_schema_missing` | Medium | The home page has no Organization (or LocalBusiness and its common subtypes) JSON-LD and no WebSite JSON-LD. |
| `jsonld_missing` | Low | An indexable page that returned HTML has no JSON-LD at all. |
| `jsonld_invalid` | Medium | A JSON-LD block does not parse. |
| `js_only_content` | Medium | An indexable HTML page has fewer than 150 visible words in its raw HTML, loads at least one external script and has an empty app container (`#root`, `#app`, `#__next`, `#__nuxt`, `#app-root`, `#svelte`, `#___gatsby`). Its text probably appears only after JavaScript runs, and GPTBot and ClaudeBot do not run JavaScript. A short page without an empty container is never flagged. |
| `snippet_blocked` | Medium | The page's robots meta tag or X-Robots-Tag contains `nosnippet` or `max-snippet:0` (bare, or for `googlebot`). Google says a page must be snippet-eligible to appear in AI Overviews and AI Mode. |
| `page_error` | Critical | A page the sitemap would list does not load. |

The robots.txt reader (`Checks::parseRobotsGroups`, `robotsBlocks`) follows Google's reading: consecutive `User-agent` lines share their rules, a bot with its own group ignores `*`, the longest matching rule wins and Allow wins a tie, `*` and a trailing `$` work in patterns. Not checked, because their effect is unverified: llms.txt, agents.md, Product and Review markup.

## How it works

- **Pages**: the home page and every live, non-redirected page from Pages, then any internal link found on the way (and, on a live site, every sitemap address), up to the page limit. Each page's JSON-LD `@type` values (top level, lists and `@graph`), its visible word count and whether it has an empty app container are stored with it.
- **Fetching**: plain HTTP to the site's own address (or the Crawl address setting), no redirects followed, no JavaScript run. Admin presses Run audit and the page calls the `step` action repeatedly, so no background process is needed. The last step runs the checks.
- **Its own traffic stays out of stats**: every request sends `User-Agent: OsmiumAIOAudit/1.0 (bot)` and an `X-Osmium-AIO-Audit` header holding a random per-run token. Core's redirect/404 log asks this service (hook `request.internal`) whether the token belongs to the run in progress, then skips logging.
- **Independent of the SEO Audit**: it shares no code with osmium-seo-audit and the two can be installed together.
- **Dev**: the PHP container cannot reach `base.localhost:8300`; set Crawl address to `http://base.localhost` (a bare `http://localhost` is redirected to the site host).

## Data

`aio_audit_runs`, `aio_audit_pages`, `aio_audit_findings` (prefixed). Uninstalling keeps them. Settings: `app/config/services/osmium-aio-audit.json.php`.
