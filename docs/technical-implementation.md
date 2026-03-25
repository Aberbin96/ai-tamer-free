# Technical Implementation Plan

This document outlines the complete technical strategy for implementing AI Tamer within the WordPress ecosystem, focusing on performance, security, and scalability. Each section maps to a phase in the [Project Roadmap](roadmap.md).

---

## Plugin Directory Structure

```
ai-tamer/
├── ai-tamer.php              # Plugin bootstrap and header
├── composer.json
├── docs/
├── includes/
│   ├── class-ai-tamer.php    # Core plugin class (init, hooks)
│   ├── class-detector.php    # Agent Detection Engine
│   ├── class-protector.php   # Header/meta injection, robots.txt
│   ├── class-logger.php      # Event logging
│   ├── class-limiter.php     # Rate Limiting logic
│   └── class-exporter.php    # Audit report generation
├── admin/
│   ├── class-admin.php       # Admin menu, settings page
│   ├── views/
│   │   ├── settings.php      # Settings form view
│   │   └── dashboard.php     # Transparency panel view
│   └── assets/
├── data/
│   └── bots.json             # Known AI agent list (User-Agents & IPs)
└── languages/
```

---

## 1. Request Lifecycle & Hooking
**→ Applies to: Phase 1, 2, 3**

To achieve maximum performance and early protection, AI Tamer hooks into the earliest possible WordPress lifecycle events.

| Hook | Purpose |
|---|---|
| `plugins_loaded` | Boot Detection Engine, read bot list |
| `init` | Initialize settings, admin UI |
| `robots_txt` | Append AI-specific rules to `robots.txt` |
| `wp_headers` | Inject HTTP `X-Robots-Tag` headers |
| `wp_head` | Inject `<meta name="robots" content="noai, noimageai">` |
| `the_content` | Conditionally filter content for training agents |
| `wp_ajax_*` | Admin endpoints for logs and export |

---

## 2. Robots.txt Management
**→ Applies to: Phase 1**

WordPress has a built-in `robots_txt` filter that lets us append rules without touching any files on disk.

```php
add_filter( 'robots_txt', 'aitamer_append_robots', 10, 2 );

function aitamer_append_robots( $output, $public ) {
    $blocked_bots = aitamer_get_blocked_bots(); // reads from settings
    foreach ( $blocked_bots as $bot ) {
        $output .= "\nUser-agent: {$bot}\nDisallow: /\n";
    }
    return $output;
}
```

- **SEO-safe**: We will never add a blanket `Disallow: /` for all bots.
- **Per-bot control**: Each agent can be toggled independently in the UI.

---

## 3. Detection Engine
**→ Applies to: Phase 1, 3**

The Detection Engine classifies every inbound request into one of three categories: `human`, `search-agent` (safe), or `training-agent` (target for protection).

### Strategy:
1. **User-Agent (UA) Matching** — Fast string/regex match against `data/bots.json`.
2. **IP Verification (CIDR)** — For critical bots (Google, OpenAI), verify IP against published ranges to prevent UA spoofing.
3. **Reverse DNS (RDNS)** — Background verification via `wp_cron`, result cached as a Transient.
4. **Result Cache** — All classifications are cached per IP+UA hash for the duration of the request and optionally persisted for N minutes.

```php
// Pseudo-code
$agent    = AiTamer\Detector::classify( $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'] );
// $agent->type  => 'training-agent'
// $agent->name  => 'GPTBot'
// $agent->score => 0.98 (confidence)
```

---

## 4. Storage & Data Persistence
**→ Applies to: Phase 2, 4**

To avoid database bloat, we use a hybrid approach.

| Data | Storage | Details |
|---|---|---|
| Plugin settings | `wp_options` | Standard Settings API |
| Bot classifications | Transients / Object Cache | TTL: 5 min per IP |
| Raw access logs | `wp_aitamer_logs` (custom table) | High-write, auto-purged |
| Aggregated stats | `wp_aitamer_stats` | Aggregated daily via WP-Cron |

### `wp_aitamer_logs` schema:
```sql
CREATE TABLE wp_aitamer_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bot_name    VARCHAR(100)     NOT NULL,
    bot_type    VARCHAR(50)      NOT NULL,
    post_id     BIGINT UNSIGNED  DEFAULT NULL,
    request_uri TEXT             NOT NULL,
    ip_hash     VARCHAR(64)      NOT NULL,  -- Hashed, never raw IP
    created_at  DATETIME         NOT NULL,
    INDEX (bot_name, created_at),
    INDEX (post_id, created_at)
);
```

---

## 5. Resource Protection (Rate Limiting)
**→ Applies to: Phase 2, 4**

Implementing a "Leaky Bucket" algorithm using Transients (or Redis Object Cache if available).

- **Thresholds**: Configurable RPM (Requests Per Minute) per bot type in the admin panel.
- **Action**: On threshold breach → HTTP `429 Too Many Requests` + `Retry-After` header.
- **Exemptions**: Human users and whitelisted search engines are never limited.

---

## 6. Selective Content Visibility
**→ Applies to: Phase 3**

To protect premium or sensitive content from being scraped for training:

- **`the_content` filter**: Strip or replace content blocks conditionally for `training-agent` requests.
- **`data-noai` attributes**: Mark specific HTML elements that the plugin will remove from bot responses.
- **Server-side only**: We will never hide content via CSS alone, since CSS is not a reliable protection.

---

## 7. License Declaration
**→ Applies to: Phase 5**

AI Tamer will expose the author's licensing intent as machine-readable signals:

- **HTTP Header**: `AI-License: no-training; no-storage` (custom, future-proof header).
- **Meta tag**: `<meta name="ai-license" content="no-training">`.
- **JSON-LD Schema**: Optional structured data block embedded in `<head>` to convey usage rights in a standardized format.

This does **not** constitute a legal contract—it is a technical declaration of intent.

---

## 8. Security Hardening
**→ Applies to: All Phases**

- **Nonces**: All admin form submissions will use `wp_nonce_field` / `check_admin_referer`.
- **Capabilities**: Transparency panel restricted to `manage_options` capability.
- **Sanitization**: All log data sanitized with `sanitize_text_field` before storage.
- **Escaping**: All output escaped with `esc_html`, `esc_attr`, `wp_kses` as appropriate.
- **GDPR**: Logs will never store raw IP addresses—only one-way hashed values.
