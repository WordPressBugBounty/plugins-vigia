=== VigIA - AI Visibility, Analytics & Control ===
Contributors: fernandot, ayudawp
Tags: ai, analytics, gpt, claude, llms
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.12.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor 55+ AI crawlers, control access via robots.txt, and boost your AI visibility with llms.txt, JSON-LD, Markdown for Agents & Visibility Score.

== Description ==

**VigIA** (Spanish for "lookout" or "watchman", incorporating "IA" - Spanish for "AI") is a complete AI visibility toolkit for WordPress. Monitor 55+ AI crawlers, control access to your content, and optimize how AI systems discover and understand your site.

= What does VigIA do? =

* **Scores your AI visibility** with a 100-point analyzer covering 20 checks across 5 categories
* **Tracks AI crawlers** visiting your site (GPTBot, ClaudeBot, PerplexityBot, and 55+ others)
* **Provides detailed analytics** with charts, statistics, and exportable reports
* **Blocks unwanted crawlers** via PHP (403 response)
* **Manages robots.txt rules** for AI crawlers with compliance monitoring
* **Sends email alerts** about crawler activity (daily, weekly, or monthly)
* **Generates llms.txt files** to help AI systems understand your site
* **Serves markdown endpoints** for individual posts and pages (Markdown for Agents standard)
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
* [AI Share & Summarize](https://wordpress.org/plugins/ai-share-summarize/) integration: see share button clicks per page
* Recent activity log with filters and paginated navigation
* Period comparison functionality
* CSV export

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
* Serve individual posts/pages as optimized markdown for AI agents
* Dedicated .md URL endpoints (e.g., /your-post.md)
* Accept: text/markdown content negotiation
* Discoverability via link headers and HTML alternate tags
* YAML frontmatter with title, date, author, categories, tags, and more
* Respects blocking rules (blocked crawlers get 403)
* Respects LLMs.txt exclusion filters
* Analytics integration: tracks markdown requests per crawler
* X-Markdown-Tokens response header
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
* Compatible with Yoast SEO, Rank Math, All in One SEO, SEOPress, and The SEO Framework

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

VigIA monitors 55+ AI crawlers including:

* **OpenAI**: GPTBot, OAI-SearchBot, ChatGPT-User
* **Anthropic**: ClaudeBot, Claude-Web, Claude-SearchBot
* **Google**: Google-Extended, GoogleOther, Gemini-Deep-Research
* **Perplexity**: PerplexityBot, Perplexity-User
* **Meta**: Meta-ExternalAgent, FacebookBot
* **Microsoft**: BingBot
* **ByteDance**: Bytespider
* **Amazon**: Amazonbot
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

If the file already exists with other content, see "Merging into an existing config file" below.

= Connecting Claude Desktop =

Save the JSON block from Quick Connect as `claude_desktop_config.json` in your **user** Library (this is not the system Library at the root of the disk):

* macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
* Windows: `%APPDATA%\Claude\claude_desktop_config.json`
* Linux: `~/.config/Claude/claude_desktop_config.json`

On macOS, the easiest way to reach the folder is to open Finder, press ⌘ Shift G, paste `~/Library/Application Support/Claude/` and hit Enter. On Windows, press Win+R and run `%APPDATA%\Claude`.

Important: **Claude Desktop only speaks stdio to local processes**, so the snippet does not connect directly to VigIA over HTTP. Instead it launches a small bridge package (`mcp-remote`) via `npx` that proxies the connection. This means you need [Node.js](https://nodejs.org/) installed on the machine. The first run downloads `mcp-remote` automatically; subsequent runs use the npm cache.

If you do not want to install Node.js, connect from Claude Code or Cursor instead — both speak HTTP MCP natively and do not need a bridge.

Restart Claude Desktop after saving the file. If the app boots with default preferences, the JSON is malformed — review the file or restore your backup. If Claude Desktop says the entry is "not a valid MCP server configuration", `npx` is not in its PATH; check that Node.js is installed and accessible to GUI apps.

If the file already exists with other content, see "Merging into an existing config file" below.

= Merging into an existing config file =

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

= Other MCP clients (Codex CLI, Continue, Cline, Antigravity, Zed, custom) =

Most MCP clients accept HTTP transport with a custom Authorization header. The Quick Connect panel exposes the two raw values you need — the server URL and the Authorization header — so you can drop them into whatever configuration format your client expects.

Browser-only assistants without an MCP client (AI Studio, ChatGPT web) cannot connect. They need a desktop or CLI client that speaks MCP over HTTP.

= Read-only mode =

If you only want your AI to consult VigIA (not change anything), enable "Read-only mode" in the MCP tab. While on, write actions (block, unblock, robots changes) return a permission denied error. Read actions (statistics, top crawlers, blocked items, robots rules) keep working.

The toggle stores a `vigia_mcp_read_only` option that hooks into the `vigia_can_write_via_abilities` filter. Developers can still force read-only from a mu-plugin:

`add_filter( 'vigia_can_write_via_abilities', '__return_false' );`

The mu-plugin filter at the default priority takes precedence over the toggle.

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

= Can I add custom crawlers to monitor? =

Yes! In the main analytics page, scroll down to "Custom crawlers" and add your own User-Agent patterns to track.

= Where is the data stored? =

All data is stored in your WordPress database in a custom table (`wp_vigia_visits`). No data leaves your server.

= What is llms.txt? =

The llms.txt file is a standard for helping AI systems understand your website's content and structure. It provides a machine-readable overview of your site that AI can use to better represent your content. Learn more at llmstxt.org.

= Which SEO plugins are supported for noindex detection? =

VigIA supports automatic noindex detection from: Yoast SEO, Rank Math, All in One SEO, SEOPress, and The SEO Framework.

= What is the Abilities API? =

The Abilities API is a new feature in WordPress 6.9 that allows plugins to expose their functionality in a standardized way. This enables AI agents, automation tools, and external systems to discover and use plugin features programmatically. VigIA implements 9 abilities for analytics, blocking, and robots.txt management.

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

= 1.12.1 =
* Fix: Fatal error "Cannot redeclare WP\MCP\constants()" when both VigIA and the standalone `mcp-adapter` plugin are active. VigIA now skips its bundled adapter bootstrap if the standalone copy has already been loaded

= 1.12.0 =
* NEW: One-click MCP setup. The MCP Adapter and php-mcp-schema dependencies now ship bundled inside the plugin, so the server is active right after install — no `composer install` and no terminal required
* NEW: "Quick connect" panel in VigIA > Extras > MCP that generates a dedicated `VigIA MCP` Application Password and renders ready-to-paste connection commands for Claude Code, Cursor, Claude Desktop and a generic block (URL + Authorization header) that fits any other MCP client (Codex CLI, Continue, Cline, Antigravity, Zed, etc.)
* NEW: Safe JSON merger for Cursor and Claude Desktop. Paste your current config file, the plugin parses it, splices VigIA into `mcpServers` preserving everything else (preferences, other servers) and returns a valid file ready to save back, with no manual comma juggling
* NEW: Detection and revocation flow for an existing `VigIA MCP` Application Password directly from the MCP tab
* NEW: "What can I ask my AI now?" section in the MCP tab with example prompts so users immediately see what the connection unlocks
* NEW: Read-only mode is now a one-click toggle in the MCP tab (option `vigia_mcp_read_only`). The `vigia_can_write_via_abilities` filter still works for developers who prefer to force it from code and takes precedence over the toggle
* Changed: Claude Desktop snippet now launches `mcp-remote` via `npx` as a stdio-to-HTTP bridge (the only way Claude Desktop can currently talk to a remote HTTP MCP server). **Requires Node.js installed on the machine.** Claude Code and Cursor speak HTTP MCP natively and need no bridge
* Changed: Manual setup snippets moved into a collapsed "Manual setup (advanced)" block to avoid competing with Quick connect; inactive-server message now references a missing `vendor/` directory instead of a missing Composer install
* Fix: `vigia/get-blocked-items`, `vigia/get-top-pages` and `vigia/block-crawler` abilities were calling non-existent methods and returned a 500 when invoked. They now resolve correctly and the output surfaces fields that were being discarded (`name` for blocked items, `crawler_count` for top pages)

= 1.11.0 =
* NEW: Native MCP server that exposes VigIA abilities at /wp-json/vigia/v1/mcp through the official WordPress MCP Adapter, ready to plug into Claude Code, Cursor or Claude Desktop
* NEW: MCP tab in VigIA > Extras with adapter status, endpoint URL, Application Password setup, connection snippets for Claude Code, Cursor and Claude Desktop, list of exposed abilities and the read-only mode snippet
* NEW: Filter `vigia_can_write_via_abilities` to disable mutating abilities (block, unblock, robots changes) while keeping read-only abilities working - useful when granting MCP access with Application Passwords
* Fix: `vigia/block-crawler` now validates IP addresses with FILTER_VALIDATE_IP, matching the behavior of the AJAX blocking handler
* Performance: results of `vigia/get-crawler-stats`, `vigia/get-top-crawlers` and `vigia/get-top-pages` are now cached for 5 minutes via transients to keep MCP clients from hammering the database
* Changed: Version history moved to a dedicated changelog.txt file served from the plugin's public SVN, keeping readme.txt focused on the current release
* Dev: introduced composer.json declaring the optional `wordpress/mcp-adapter` dependency

= 1.10.2 =
* Fix: Removed stray whitespace before PHP opening tag in class-visibility-analyzer.php that caused "headers already sent" warnings and could break other plugins functionality

= 1.10.1 =
* Fixed: AI Visibility Analyzer now detects sitemaps.xml (plural) in addition to sitemap.xml, sitemap_index.xml and wp-sitemap.xml

For older changelog entries, please check the [changelog.txt](https://plugins.svn.wordpress.org/vigia/trunk/changelog.txt) file

== Upgrade Notice ==

= 1.12.1 =
Fixes a fatal error when the standalone MCP Adapter plugin is also active alongside VigIA. Recommended for everyone running both.

= 1.12.0 =
MCP server active out of the box: dependencies bundled (no Composer), new Quick Connect panel with ready-to-paste connection commands and a one-click read-only toggle. Three MCP abilities fixed. Note: connecting Claude Desktop now requires Node.js installed.

= 1.11.0 =
New MCP server makes VigIA usable from Claude Code, Cursor and Claude Desktop. Install the WordPress MCP Adapter via composer install to enable it. Existing Abilities API integrations keep working without changes.

= 1.10.2 =
Fixes a PHP warning in the Visibility Analyzer that could interfere with other plugins sending HTTP headers.

= 1.10.1 =
AI Visibility Analyzer now detects sitemaps.xml (plural) for sites using that convention.

== Support ==

Need help or have suggestions?

* [Official website](https://servicios.ayudawp.com)
* [WordPress support forum](https://wordpress.org/support/plugin/vigia/)
* [YouTube channel](https://www.youtube.com/AyudaWordPressES)
* [Documentation and tutorials](https://ayudawp.com)

Love the plugin? Please leave us a 5-star review and help spread the word!

== About AyudaWP ==

We are specialists in WordPress security, SEO, AI and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.