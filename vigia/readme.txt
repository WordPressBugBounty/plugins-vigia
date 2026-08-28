=== VigIA - AI Visibility, Analytics & Control ===
Contributors: fernandot, ayudawp
Tags: ai, analytics, gpt, claude, llms
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor 60+ AI crawlers, control access via robots.txt, and boost your AI visibility with llms.txt, JSON-LD, Markdown for Agents & Visibility Score.

== Description ==

**VigIA** (Spanish for "lookout" or "watchman", incorporating "IA" - Spanish for "AI") is a complete AI visibility toolkit for WordPress. Monitor 60+ AI crawlers, control access to your content, and optimize how AI systems discover and understand your site.

= What does VigIA do? =

* **Scores your AI visibility** with a 100-point analyzer covering 20 checks across 5 categories
* **Tracks AI crawlers** visiting your site (GPTBot, ClaudeBot, PerplexityBot, and 60+ others)
* **Provides detailed analytics** with advanced filters, server-side pagination, and exportable reports with metadata banner
* **Blocks unwanted crawlers** via PHP (403 response)
* **Manages robots.txt rules** for AI crawlers with compliance monitoring
* **Sends email alerts** about crawler activity (daily, weekly, or monthly)
* **Generates llms.txt files** to help AI systems understand your site
* **Serves markdown endpoints** for posts, pages, taxonomy archives (categories, tags, WooCommerce product categories, custom taxonomies) and WooCommerce products with schema-like data
* **Generates JSON-LD structured data** with Site Identity and AI Discovery signals
* **Exposes abilities** for AI agents and automation tools (WordPress 6.9+)

= Key Features =

**AI Visibility Analyzer**
* 100-point scoring system with letter grades (A+ to F)
* 20 individual checks across 5 categories
* Access & AI Discovery (37 pts): robots.txt, AI bot directives, Content Signals, llms.txt, sitemap, RSS feed
* Structured Data & Semantic Context (25 pts): JSON-LD schemas, Open Graph, Twitter Cards, meta description, canonical URL
* Content Structure & Readability (20 pts): heading hierarchy, semantic HTML5, image alt text, content/HTML ratio
* AI Interaction & Distribution (8 pts): markdown delivery, AI share buttons
* Access Performance (10 pts): TTFB measurement
* Smart recommendations with direct links to VigIA features and plugin suggestions
* Analyze any page on your site with URL autocomplete selector
* Results cached for 24 hours with manual re-analyze option

**Analytics Dashboard**
* Total visits, unique crawlers, and pages crawled statistics
* Timeline chart with daily breakdown
* Category distribution (AI Training, AI Search, AI Assistant, Data Scraper)
* Top crawlers and most crawled pages tables with paginated navigation
* [Share Buttons & AI-powered Summaries](https://wordpress.org/plugins/ai-share-summarize/) integration: see share button clicks per page
* Recent activity log with content type and HTTP status columns (color coded by status family)
* Advanced filters: multi-select crawler picker, content type, HTTP status code, and configurable date range
* Server-side pagination with four-button pager (first, previous, next, last) — operates over the full database, not just the latest 500 rows
* Period comparison functionality
* CSV export with a metadata banner (site name, site URL, export type, date range, export timestamp, applied filters)
* "Export filtered CSV" button that downloads exactly what the active filters return, with `vigia-filtered-YYYY-MM-DD.csv` filename
* Content type detection distinguishes Home, Post, Page, Product, custom CPTs, Category archive, Tag archive, Date/Author archive, Feed, Sitemap, REST API, File, Admin / login attempts (`/wp-admin`, `/wp-login.php`), WordPress system (admin-ajax, xmlrpc, wp-cron, wp-comments-post), 404 Not found, and Other

**Crawler Blocking**
* Block crawlers via PHP with 403 Forbidden response
* Quick block dropdown in analytics dashboard
* Manage blocks from Extras page
* Works on any server (Apache, Nginx, LiteSpeed, etc.)

**Robots.txt Management**
* Add Disallow rules for AI crawlers
* Visual preview of your robots.txt
* Compliance monitoring: see which crawlers ignore your rules
* One-click blocking for non-compliant crawlers
* Works with both physical and virtual robots.txt

**Email Alerts**
* Daily, weekly, or monthly reports
* Three detail levels: Minimal, Normal, Complete
* Non-compliant crawler warnings
* Activity comparison with previous period

**Markdown for Agents**
* Serve posts, pages and any public post type as optimized markdown for AI agents
* Serve taxonomy archive pages (categories, tags, WooCommerce product categories, custom taxonomies) as markdown — disabled by default, opt in per taxonomy
* Dedicated .md URL endpoints (e.g., `/your-post.md`, `/category/news.md`, `/product-category/electronics.md`)
* Accept: text/markdown content negotiation on posts and taxonomy archive pages
* Discoverability via Link HTTP headers and `<link rel="alternate" type="text/markdown">` HTML tags
* YAML frontmatter for posts: title, date, modified, author, image, categories, tags, post type, lang
* YAML frontmatter for taxonomy terms: title, description, url, type, taxonomy, parent, count, image (term meta), lang
* WooCommerce product frontmatter adds schema-like fields: sku, product_type, price, regular_price, sale_price, currency, availability, stock_quantity, rating, rating_count, review_count
* Taxonomy term body includes the term description (rendered through `the_content`), the list of direct child terms in hierarchical taxonomies, and an excerpt of the latest posts/products assigned to the term
* Product listings inside `product_cat` archives include an inline summary with formatted price, "was X" on sale items, star rating and out-of-stock flag
* Respects blocking rules (blocked crawlers get 403) and LLMs.txt exclusion filters
* Per-term noindex detection from Yoast SEO, Rank Math, All in One SEO and SEOPress
* Analytics integration: tracks markdown requests per crawler
* X-Markdown-Tokens response header
* Filters: `vigia_markdown_post_eligible`, `vigia_markdown_term_eligible`, `vigia_markdown_term_posts_limit`
* Follows the Cloudflare Markdown for Agents standard

**LLMs.txt Generator**
* Select content by post type with one click
* Filter by taxonomies (categories, tags, custom)
* Manual include/exclude with AJAX search
* Exclude by URL patterns (wildcards supported)
* SEO plugin integration (auto-exclude noindex content)
* Auto-regeneration (daily, weekly, monthly)
* Robots.txt integration (add llms.txt and llms-full.txt references)
* Generate llms.txt and llms-full.txt files
* Full content or excerpt mode
* Compatible with Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, and Native SEO NoIndexer

**JSON-LD Structured Data**
* Generate WebSite and Organization/Person schema for site identity
* AI Discovery: ReadAction pointers to llms.txt, llms-full.txt, and Markdown for Agents endpoints
* Social profiles and sameAs links for brand identity across the web
* SearchAction for Google sitelinks search box
* Media library integration for logo selection
* SEO plugin conflict detection (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework)
* Choose output page (front page or any published page)
* Live JSON-LD preview with real-time updates
* Smart integration with LLMs.txt and Markdown for Agents features
= Supported AI Crawlers =

VigIA monitors 60+ AI crawlers including:

* **OpenAI**: GPTBot, OAI-SearchBot, OAI-AdsBot, ChatGPT-User
* **Anthropic**: ClaudeBot, Claude-SearchBot, Claude-User, Claude-Code
* **Google**: Google-Extended, GoogleOther, Gemini-Deep-Research, Google-NotebookLM
* **Perplexity**: PerplexityBot, Perplexity-User
* **Meta**: Meta-ExternalAgent, FacebookBot, Meta-WebIndexer
* **Amazon**: Amazonbot, Amzn-SearchBot, bedrockbot
* **Mistral**: MistralAI-User, MistralAI-Index
* **Microsoft**: BingBot
* **ByteDance**: Bytespider
* **Apple**: Applebot-Extended
* **And many more...**

= Privacy Focused =

VigIA stores visitor data locally in your WordPress database. No data is sent to external servers.

== Abilities API ==

VigIA is one of the first WordPress plugins to implement the [Abilities API](https://developer.wordpress.org/apis/abilities-api/) introduced in WordPress 6.9. This API allows AI agents, automation tools, and external systems to discover and interact with VigIA's functionality in a standardized, secure way.

= What are Abilities? =

Abilities are self-contained units of functionality that VigIA exposes through WordPress's central registry. Each ability has defined inputs, outputs, and permissions, making it easy for automation tools to understand and use them.

= Available Abilities =

VigIA registers the following abilities:

**Analytics**

* `vigia/get-crawler-stats` - Get statistics about AI crawler visits (total visits, unique crawlers, pages crawled)
* `vigia/get-top-crawlers` - Get a ranked list of most active AI crawlers
* `vigia/get-top-pages` - Get the most crawled pages on your site

**Blocking**

* `vigia/get-blocked-items` - List all blocked crawlers and IP addresses
* `vigia/block-crawler` - Block a crawler by User-Agent pattern
* `vigia/unblock-crawler` - Remove an existing block

**Robots.txt**

* `vigia/get-robots-rules` - Get current AI crawler rules in robots.txt
* `vigia/add-robots-disallow` - Add a Disallow directive for a crawler
* `vigia/remove-robots-rule` - Remove a robots.txt rule

= Use Cases =

* **Automated monitoring**: AI agents can query crawler statistics and alert you to anomalies
* **Reactive blocking**: Automation tools can block crawlers that repeatedly ignore robots.txt
* **External dashboards**: Aggregate data from multiple WordPress sites with VigIA installed
* **WP-CLI integration**: Future command-line access through the Abilities API
* **n8n / Make workflows**: Build custom automation flows using VigIA's abilities

= Requirements =

The Abilities API ships with WordPress 6.9 and later. On older WordPress versions, VigIA works normally but abilities and MCP are not available.

== MCP Server (Model Context Protocol) ==

VigIA exposes its 9 abilities as native MCP tools to any MCP-compatible client (Claude Code, Cursor, Claude Desktop, Codex CLI, Antigravity, Continue, Cline, Zed and similar) using the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter). The adapter ships bundled with the plugin, so the MCP endpoint is active right after installation — no Composer step or terminal access required.

= Requirements =

* WordPress 6.9 or later (provides the Abilities API)

= Quick connect (recommended) =

Open **VigIA > Extras > MCP** and click "Generate password and connection commands". The plugin creates a dedicated Application Password named `VigIA MCP` and renders ready-to-paste commands for Claude Code, Cursor, Claude Desktop and a generic block (URL + Authorization header) for any other MCP client.

The plain password is shown only once. If you lose it, revoke the entry from the same panel and generate a new one.

= Endpoint =

`https://your-site.example/wp-json/vigia/v1/mcp`

The endpoint uses HTTP Basic auth with the WordPress Application Password. The user must have the `manage_options` capability.

= Connecting Claude Code =

Quick Connect builds the full command for you. The shape is:

`claude mcp add --transport http vigia https://your-site.example/wp-json/vigia/v1/mcp --header "Authorization: Basic BASE64_OF_USER_AND_APP_PASSWORD"`

Claude Code merges the new entry into its config file automatically — no risk of breaking other servers.

= Connecting Cursor =

Save the JSON block from Quick Connect as `~/.cursor/mcp.json`. You can also reach this file from inside Cursor at *Settings → Cursor Settings → MCP*.

If the file already exists with other content, see the FAQ.

= Claude Desktop and other clients =

Claude Desktop does not speak HTTP MCP, so it needs a small bridge and a config file of its own. Any other client (Codex CLI, Continue, Cline, Antigravity, Zed, or your own) takes the two raw values Quick Connect exposes: the server URL and the Authorization header. Both cases are covered in the FAQ, together with how to merge VigIA into a config file that already exists without losing what is in it.

= Read-only mode =

If you only want your AI to consult VigIA (not change anything), enable "Read-only mode" in the MCP tab. While on, write actions (block, unblock, robots changes) return a permission denied error. Read actions (statistics, top crawlers, blocked items, robots rules) keep working.

The toggle stores a `vigia_mcp_read_only` option that hooks into the `vigia_can_write_via_abilities` filter. Developers can still force read-only from a mu-plugin:

`add_filter( 'vigia_can_write_via_abilities', '__return_false' );`

The mu-plugin filter at the default priority takes precedence over the toggle.

= Who can reach the endpoint =

The endpoint requires the capability to manage options, the same one every tool behind it already asked for. The `vigia_mcp_transport_capability` filter can lower that bar; each tool keeps its own permission check.

= After connecting =

Restart your MCP client after adding the server so it picks up the new tools. Then try a few prompts to confirm everything is wired up:

* "Show me VigIA crawler stats for the last 7 days."
* "List the top 5 most crawled pages on this site."
* "Add a robots.txt Disallow rule for TestBot and then list the current AI crawler rules."

The third example exercises a read + write + read round-trip, which is the most complete sanity check.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/vigia/` or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to **VigIA > AI Score** to check your AI Visibility Score
4. View crawler analytics in **VigIA > Analytics**
5. Configure blocking rules, llms.txt, markdown, and JSON-LD in **VigIA > Extras**

== Frequently Asked Questions ==

= What is the AI Visibility Score? =

The AI Visibility Score is a 100-point rating system that measures how well your site is prepared for AI crawlers and AI-powered search. It checks 20 signals across 5 categories: access and discovery, structured data, content structure, AI interaction, and performance. You get a letter grade (A+ to F) and specific recommendations to improve.

= Does this plugin slow down my site? =

No. VigIA adds minimal overhead by checking the User-Agent string on each request. The check is very fast and only writes to the database when an AI crawler is detected.

= What's the difference between robots.txt blocking and PHP blocking? =

**Robots.txt** is advisory - crawlers should respect it but may choose to ignore it. **PHP blocking** returns a 403 Forbidden response, effectively preventing access regardless of whether the crawler respects robots.txt.

= Will blocking crawlers affect my SEO? =

Blocking AI training crawlers (like GPTBot or ClaudeBot) will not affect traditional search engine rankings. However, blocking AI search crawlers might affect how your content appears in AI-powered search results.

= What is Markdown for Agents? =

Markdown for Agents is a standard for serving web content as clean markdown to AI agents. Instead of processing full HTML, agents receive lightweight markdown with structured metadata. VigIA supports both dedicated .md URLs and Accept: text/markdown content negotiation. Enable it in VigIA > Extras > Markdown for Agents.

This feature also covers taxonomy archives (categories, tags, WooCommerce product categories, custom taxonomies) and WooCommerce products. Term archives include the term description, the list of child terms in hierarchical taxonomies and an excerpt of the latest entries; product `.md` endpoints embed schema-like data (price, sale price, SKU, stock status, rating) directly in the YAML frontmatter.

= Which content types does the activity table classify? =

VigIA classifies each crawler hit into one of these buckets, indexed in the database so filters and CSV exports are instant: Home (the `/` path), Post, Page, Product, any other public custom post type, Category archive, Tag archive, Date/Author archive, Feed, Sitemap, REST API, File (PDFs, images, downloads), Admin/login attempt (`/wp-admin`, `wp-login.php` — useful to spot bots probing the admin), WordPress system (admin-ajax, xmlrpc, wp-cron, wp-comments-post), 404 Not found, and Other.

= Can I add custom crawlers to monitor? =

Yes! In the main analytics page, scroll down to "Custom crawlers" and add your own User-Agent patterns to track.

= Where is the data stored? =

All data is stored in your WordPress database in a custom table (`wp_vigia_visits`). No data leaves your server.

= What is llms.txt? =

The llms.txt file is a standard for helping AI systems understand your website's content and structure. It provides a machine-readable overview of your site that AI can use to better represent your content. Learn more at llmstxt.org.

= Which SEO plugins are supported for noindex detection? =

VigIA supports automatic noindex detection from: Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, and Native SEO NoIndexer.

= What is the Abilities API? =

The Abilities API is a new feature in WordPress 6.9 that allows plugins to expose their functionality in a standardized way. This enables AI agents, automation tools, and external systems to discover and use plugin features programmatically. VigIA implements 9 abilities for analytics, blocking, and robots.txt management.

= How do I connect Claude Desktop? =

Save the JSON block from Quick Connect as `claude_desktop_config.json` in your **user** Library (this is not the system Library at the root of the disk):

* macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
* Windows: `%APPDATA%\Claude\claude_desktop_config.json`
* Linux: `~/.config/Claude/claude_desktop_config.json`

On macOS, the easiest way to reach the folder is to open Finder, press ⌘ Shift G, paste `~/Library/Application Support/Claude/` and hit Enter. On Windows, press Win+R and run `%APPDATA%\Claude`.

Important: **Claude Desktop only speaks stdio to local processes**, so the snippet does not connect directly to VigIA over HTTP. Instead it launches a small bridge package (`mcp-remote`) via `npx` that proxies the connection. This means you need [Node.js](https://nodejs.org/) installed on the machine. The first run downloads `mcp-remote` automatically; subsequent runs use the npm cache.

If you do not want to install Node.js, connect from Claude Code or Cursor instead — both speak HTTP MCP natively and do not need a bridge.

Restart Claude Desktop after saving the file. If the app boots with default preferences, the JSON is malformed — review the file or restore your backup. If Claude Desktop says the entry is "not a valid MCP server configuration", `npx` is not in its PATH; check that Node.js is installed and accessible to GUI apps.

If the file already exists with other content, see the next question.

= How do I add VigIA to an MCP config file that already exists? =

If your `claude_desktop_config.json` or `~/.cursor/mcp.json` already exists, do **not** paste the full Quick Connect block on top of it. Pasting on top discards everything else (preferences, other MCP servers) and the app will start with defaults.

**Always make a backup of the file first.** Then open it with any text editor that preserves JSON.

There are two scenarios.

**Scenario 1 — the file has content but no mcpServers block yet.**

This is common when you have used Claude Desktop before but never configured MCP servers. The file might look like this:

`{
  "preferences": {
    "menuBarEnabled": false,
    "...": "..."
  }
}`

Add `mcpServers` as a sibling property of `preferences`, separated by a comma. The result should be:

`{
  "preferences": {
    "menuBarEnabled": false,
    "...": "..."
  },
  "mcpServers": {
    "vigia": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-site.example/wp-json/vigia/v1/mcp",
        "--header",
        "Authorization: Basic BASE64_OF_USER_AND_APP_PASSWORD"
      ]
    }
  }
}`

The order of `preferences` and `mcpServers` is not important, but the comma between them is required. Forgetting the comma makes the JSON invalid and Claude Desktop will start with default preferences.

**Scenario 2 — the file already has mcpServers with other servers.**

Add the `vigia` entry inside the existing `mcpServers` object, separated from other entries by a comma:

`"mcpServers": {
  "other-server": {
    "...": "..."
  },
  "vigia": {
    "command": "npx",
    "args": [
      "-y",
      "mcp-remote",
      "https://your-site.example/wp-json/vigia/v1/mcp",
      "--header",
      "Authorization: Basic BASE64_OF_USER_AND_APP_PASSWORD"
    ]
  }
}`

For Cursor the entry is different — Cursor speaks HTTP MCP natively, so its block uses `type`, `url` and `headers` directly inside the server entry instead of the bridge command. The Quick Connect panel renders the right format for each client.

= Can I use an MCP client other than Claude Code, Cursor or Claude Desktop? =

Most MCP clients accept HTTP transport with a custom Authorization header. The Quick Connect panel exposes the two raw values you need — the server URL and the Authorization header — so you can drop them into whatever configuration format your client expects.

Browser-only assistants without an MCP client (AI Studio, ChatGPT web) cannot connect. They need a desktop or CLI client that speaks MCP over HTTP.

= What does JSON-LD do? =

JSON-LD (JavaScript Object Notation for Linked Data) is structured data that helps search engines and AI systems understand your site identity and content. VigIA generates two types of JSON-LD: Site Identity (WebSite + Organization/Person schema with social profiles) and AI Discovery (ReadAction pointers to your llms.txt and Markdown for Agents endpoints). This makes your AI-ready content discoverable through structured signals. Enable it in VigIA > Extras > JSON-LD.

== Screenshots ==

1. AI Visibility Analyzer with score, grade, and recommendations
2. Main analytics dashboard with charts and statistics
3. Timeline showing crawler activity over time
4. Top crawlers and most crawled pages tables
5. Recent activity log with filtering
6. Custom crawler configuration
7. Dashboard widget
8. Extras page - Robots.txt management and blocking
9. Extras page - Email alerts configuration
10. Extras page - LLMs.txt generator with content type selection
11. Extras page - Markdown for Agents configuration
12. Extras page - JSON-LD structured data configuration
13. Extras page - MCP server status, endpoint, client connection snippets and exposed abilities

== Changelog ==

= 2.6.3 =
* Improved: The MCP endpoint now asks for the same capability as the tools it exposes. It was left at the default of the bundled adapter, which any subscriber meets, so a logged-in user with no rights over the site could reach the endpoint and list the available tools. Running any of them was never possible, because all nine ask on their own for the capability to manage options, and that has not changed. A vigia_mcp_transport_capability filter is there for an install that needs a different bar.

= 2.6.2 =
* Improved: The Markdown responses now carry an X-Content-Type-Options: nosniff header, so a browser cannot second-guess their content type and decide to treat them as HTML.
* Fix: The content search in the LLMs.txt generator did not escape post titles correctly before listing the results, so a title containing quotes could break out of an HTML attribute and run script in the browser of the administrator running the search. Titles come from anyone who can publish, the Author role included. The escaping function now encodes quotes, the same way the rest of the plugin already did.
* Fix: On multisite, robots.txt, llms.txt and llms-full.txt sit at the network root and are one set of files for every site in the network, yet any subsite administrator could rewrite them. Only the main site writes them now. Subsites keep their own rules and keep serving them through the virtual robots.txt, which is per site.
* Fix: Dismissing the activation notice checked that the request came from a page of the plugin, but not that whoever sent it was allowed to change a site option. It now checks the capability like every other handler does.

= 2.6.1 =
* Improved: The Markdown version of an entry is built once and kept, instead of being converted from scratch on every request. On a 55 KB entry the response went from 74 to 22 milliseconds. It is rebuilt as soon as the entry changes, or a term whose document lists it.
* Improved: A Markdown response may now be reused for an hour, where it used to tell every agent and proxy not to store it at all. The document is the same one for whoever asks, built as a logged-out visitor, so there was nothing to protect by refusing to cache it. On a site with crawler blocks configured it is marked private instead, so a cache in front of the site never gets to answer for a crawler your site would have turned away.
* Improved: The MCP tools now tell the client what each of them does to your site. The five that read are marked read-only, and of the four that change something, blocking a crawler and adding a Disallow rule are marked as additive, while unblocking a crawler and removing a rule are marked as destructive. All nine looked alike until now, so asking for crawler statistics got the same approval prompt as blocking a crawler in Claude Desktop, Claude Code or Cursor.
* Improved: The bundled WordPress MCP Adapter is updated from 0.5.0 to 0.6.1, along with the php-mcp-schema library it depends on. There is nothing to install: both ship inside the plugin, as always.
* Fix: A page that also answers in Markdown now says so on its HTML response too, with a Vary: Accept header. Without it a shared cache, a CDN or a proxy, stores the HTML with no idea the address has a second form, and can later hand that HTML to an agent asking for Markdown. This covers the HTML WordPress generates, not what a page cache serves straight from disk, and Cloudflare ignores Vary on HTML, where the equivalent is a Cache Rule.

= 2.6.0 =
* New: Every page now advertises the llms.txt that covers it, with a rel="describedby" link in the head and as a Link header, and also on the .md responses, which have no head to carry it. This is the discovery mechanism version 2 of the llms.txt specification settled on, published in August 2026 and answering the question people asked most in two years of adoption: given a page, how does an agent find the llms.txt that describes it without guessing.
* New: The Markdown version of an entry also answers at the URL forms the specification writes down for addresses with no file name, /your-post/index.md and /your-post/index.html.md, alongside the /your-post.md the plugin publishes and links to. Nothing changes in the URLs advertised: the extra forms are there for agents that build the address themselves from the specification.
* Improved: llms.txt links each entry to its Markdown version instead of to the page, which is what version 2 of the specification asks for, that the links in the file point at content already clean for a language model. The decision is made per entry, so anything with no Markdown version of its own, or whose address does not resolve to one, keeps linking to the page exactly as before. If Visibility is the one serving the Markdown endpoint on your site, its entries are used.
* Improved: Tested up to WordPress 7.1.
* Improved: llms-full.txt is listed in the Optional section the specification defines for secondary links, and as a list item instead of a sentence. Sections in the file are lists of links, so the old paragraph was skipped by anything reading the file to the letter.
* Improved: The llms.txt reference in robots.txt is a single commented line pointing at the index, and llms-full.txt is no longer referenced there: it is linked from inside llms.txt, in the Optional section, which is the entry point an agent reads first. The checkbox for the llms-full.txt reference is gone from the settings, and with both this plugin and Visibility installed only the one actually serving llms.txt writes the line, so robots.txt never carries the same URL twice.
* Fix: A .md address with a trailing slash served the same document all over again at a second URL, with no canonical between the two. WordPress trims that slash before matching the rewrite rule, so /your-post.md/ reached the endpoint exactly like /your-post.md. It now redirects to the real address.
* Fix: The llms.txt and llms-full.txt references in robots.txt are written as comments now. They were plain lines, and Google Search Console reports a robots.txt containing syntax it does not recognise as invalid, which is an alarming red mark on a screen site owners check. No crawler ever read those lines as directives, so nothing is lost, and the reference stays there for anyone opening the file. A physical robots.txt has its old lines replaced, not duplicated.
* Fix: A site description containing a line break broke the summary of llms.txt. Only its first line was quoted and the rest fell into the body of the file as loose text.
* Fix: The AI Visibility Score no longer marks a correctly formatted llms.txt as badly formatted. The check looked for a heading at the start of a line, but the byte order mark the plugin writes at the very beginning of the file sits in front of it, so a file with a title and a summary but no entries yet failed every test and lost points for a format that was correct.

For older changelog entries, please check the [changelog.txt](https://plugins.svn.wordpress.org/vigia/trunk/changelog.txt) file

== Upgrade Notice ==

= 2.6.3 =
The MCP endpoint now asks for the same capability as the tools it exposes, instead of the default of the bundled adapter, which any subscriber meets. No tool was ever runnable by them, so nothing changes for administrators.

== Support ==

Need private support or custom development?

Do you need one-on-one help, priority troubleshooting, or a custom feature, integration, or tweak built specifically for your site? I offer private support and custom development. Just [contact me](mailto:vigia@ayudawp.com) and tell me what you need.

Need help or have suggestions?

* [Official website](https://servicios.ayudawp.com)
* [WordPress support forum](https://wordpress.org/support/plugin/vigia/)
* [YouTube channel](https://www.youtube.com/AyudaWordPressES)
* [Documentation and tutorials](https://ayudawp.com)

Love the plugin? Please leave us a 5-star review and help spread the word!

== About AyudaWP ==

We are specialists in WordPress security, SEO, AI and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.