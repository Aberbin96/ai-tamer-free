=== AI Tamer — Scraper & Crawler Protection ===
Contributors: alejandroberbin
Donate link: https://github.com/Aberbin96/ai-tamer-free
Tags: ai, protection, scraper, training, seo
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control how AI agents consume your content. Protect your intellectual property without sacrificing SEO.

== Description ==

AI Tamer — Scraper & Crawler Protection is a WordPress plugin designed to solve the "All or Nothing" dilemma of AI crawling. It provides a multi-layer defense system to differentiate between helpful indexing (SearchGPT, Gemini, Perplexity) and unauthorized data scraping for model training.

Key Features:
* **Selective Control**: Choose which AI agents can access your content.
* **Granular Protection**: Inject modern metadata (noai, noimageai) and HTTP headers (X-Robots-Tag).
* **AI Bot Detection**: Advanced detection of AI crawlers and scrapers.
* **Bandwidth Limiting**: Cap the amount of data AI bots can consume from your site daily.
* **Visibility**: Track AI consumption logs to understand how models are using your content.
* **Audit Reports**: Generate CSV evidence of AI bot activity on your site.

== Installation ==

1. Upload the `ai-tamer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your preferences in the 'AI Tamer' settings page.

== Third-Party Services ==

This plugin utilizes the following third-party service to maintain an up-to-date protection engine:

* **GitHub Bot List**: The plugin daily fetches a curated list of known AI agents and training bots from our public GitHub repository (`https://raw.githubusercontent.com/Aberbin96/ai-tamer/main/data/bots.json`). This is used to ensure the detection engine stays effective against newly discovered scrapers. No site information or user data is transmitted during this request.

== Third-Party Services ==

This plugin utilizes the following third-party service to maintain an up-to-date protection engine:

* **GitHub Bot List**: The plugin daily fetches a curated list of known AI agents and training bots from our public GitHub repository (`https://raw.githubusercontent.com/Aberbin96/ai-tamer/main/data/bots.json`). This is used to ensure the detection engine stays effective against newly discovered scrapers. No site information or user data is transmitted during this request.

== Frequently Asked Questions ==

= Does this block Google? =
No, AI Tamer allows you to distinguish between Google Search indexing and Google Extended (AI training).

== Changelog ==

= 0.1.1 =
* Improved AI detection engine with stealth bot recognition.
* Enhanced logging with protection levels and full User-Agent strings.

= 0.1.0 =
* Initial Version.
* Content protection with noai and noimageai metadata.
* AI bot detection and rate limiting.
* Daily bandwidth capping for bots.
* Audit reports (CSV export).
* HTTP protection headers (X-Robots-Tag).
* Bot license token management (HMAC signed).

== Screenshots ==

1. **Dashboard**: Overview of AI bot activity and protection status.
2. **General Settings**: Configure robots.txt and meta tag protection.
3. **Audit Reports**: Generate CSV evidence of crawler access.
5. **Post Protection**: Granular AI control for individual posts.
