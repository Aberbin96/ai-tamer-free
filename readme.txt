=== AI Tamer ===
Contributors: alejandroberbin
Donate link: https://example.com/
Tags: ai, protection, scraper, training, seo
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control how AI agents consume your content. Protect your intellectual property without sacrificing SEO.

== Description ==

AI Tamer is a WordPress plugin designed to solve the "All or Nothing" dilemma of AI crawling. It provides a multi-layer defense system to differentiate between helpful indexing (SearchGPT, Gemini, Perplexity) and unauthorized data scraping for model training.

Key Features:
* **Selective Control**: Choose which AI agents can access your content.
* **Granular Protection**: Inject modern metadata (noai, noimageai) and HTTP headers.
* **Visibility**: Track AI consumption logs to understand how models are using your content.
* **Content Triaging**: Define what parts of your HTML are "consumible" by AI.
* **Dynamic Detections**: Stay updated against new AI agents and RAG implementations.

== Installation ==

1. Upload the `ai-tamer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your preferences in the AI Tamer settings page.

== Frequently Asked Questions ==

= Does this block Google? =
No, AI Tamer allows you to distinguish between Google Search indexing and Google Extended (AI training).

== Changelog ==

= 0.1.0 =
* Initial skeleton and project definition.
