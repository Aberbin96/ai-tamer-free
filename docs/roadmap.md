# Project Roadmap

## Phase 1: MVP (Control & Defense) ✅

- [x] Project definition and skeleton.
- [x] Basic `robots.txt` and meta tag management.
- [x] Global `noai` / `noimageai` toggle.
- [x] Initial list of known AI agents.

## Phase 2: Visibility & Monitoring ✅

- [x] Lightweight logging system (per-article tracking).
- [x] Admin dashboard with visual consumption metrics.
- [x] Infrastructure protection: Basic rate limiting for bots.

## Phase 3: Advanced Triage & Selective Visibility ✅

- [x] Per-post controls for protection.
- [x] Content filtering (selective visibility for images/text blocks).
- [x] Dynamic update of the agent blacklist (Cloud-synced).

## Phase 4: Infrastructure & Audit ✅

- [x] Advanced resource limiting (Bandwidth/PPM).
- [x] Audit report generation (Downloadable evidence).
- [x] Auto-cleanup of logs and resource management.

## Phase 5: Intent and Licensing ✅

- [x] Machine-readable license headers.
- [x] Integration with automated licensing protocols (L402 / Digital Signatures).
- [x] Token Registry for manual license issuance and revocation.

> [!NOTE]
> All phases up to Phase 11 are complete. The following items represent the remaining vision and development has not yet started.

## Phase 6: Expanded Connectivity & Structured Data (v2) ✅

- [x] **RAG & Grounding Optimization**: Direct JSON/Markdown output for AI agents to prevent HTML noise.
- [x] **Machine-Readable Licenses**: Automated technical negotiation for bot access.
- [x] **Granular Media Control**: Selective blocking for image/video training (e.g., Midjourney/DALL-E) vs. text citation.
- [x] **Discovery Protocol (MCP)**: Implementation of Model Context Protocol for autonomous agent discovery (Endpoint `/catalog`).
- [x] **Smart Tolls**: Integration with payment gateways (Stripe) for per-query monetization.
- [x] **Machine-Readable Discovery**: Implementation of the `llms.txt` standard for autonomous agent discovery.

## Phase 7: Active Defense & Origin Proof (v3) ✅

- [x] **Performance Fallback**: Multi-tier caching (Object Cache + Transients) for all environments.
- [x] **Advanced Auditing**: Enriched logs with IP, User-Agent, and protection metadata.
- [x] **Smart Fingerprinting**: Beyond UA matching—checking browser headers (`Sec-Fetch-*`).
- [x] **C2PA Implementation**: Cryptographic "Proof of Human Origin" using the latest `DigitalDocument` / `CreativeWork` schema standards.
- [x] **IPTC Certification**: Manual "Human Origin" certification for media assets (IPTC 2:228) via the WordPress Media Library.
- [x] **Grammatical Watermarking**: Dual-layer invisible stylistic DNA to track content attribution in AI outputs.
- [x] **Micropayments (Protocol 402)**: Real-time billing for high-volume database access (Consolidated into Defense Strategies).
- [x] **Subscription-Linked Tokens**: Tokens validated against real-time Stripe subscription status.
- [x] **Scoped Access**: Granular control (Global vs. Post vs. Category).
- [x] **Billing History**: Integrated transaction log for AI license purchases and automated vouchers.
- [x] **Stripe Return URLs**: Make success/cancel URLs configurable or dynamic for better integration (e.g., custom landing pages).

## Strategic Vision (2026-2027)

### V1: Control & Visibility (Complete ✅)

- [x] Advanced blocking, granular headers, and detailed logs.

### V2: Volume Licensing (Prepaid & Subscriptions) (Complete ✅)

- [x] **Reading Vouchers**: Vouchers based on request count (e.g. 1k readings) to avoid Stripe transaction minimums.
- [x] **Quantity-based Validation**: Tokens authorized by request quota rather than time/post scope.
- [x] **Agent Balance API**: Allow bots to query their remaining credits via REST.

### V3: Real-time Streaming (Lightning/L402) — Active 🚀

- [x] **Lightning Node Integration**: Native configuration for Alby, LNbits, Strike API, or personal LND/CoreLightning nodes to receive programmatic micropayments.
- [x] **L402 Protocol Implementation**:
    - [x] **402 Payment Required**: HTTP middleware returning `402 Payment Required` along with an LN invoice and a Macaroon (LSAT).
    - [x] **LSAT Verification**: Cryptographic validation of paid invoices to automatically unlock protected endpoints (`WP_REST_Response`).
- [x] **Dynamic Pricing Engine**:
    - [x] **Fiat-to-Satoshi Conversion**: Automatic conversion from local currencies (USD/EUR) to Satoshis using real-time oracles.
    - [x] **Per-Post Pricing Override**: Add a field in the MetaBox to define a custom price for single articles, with the global setting as a placeholder.
    - [x] Calculate Satoshis per request dynamically based on bot type, content length, or server resources consumed.
- [x] **Ecosystem Compatibility Analysis**:
    - [x] Seamless flow with browser extensions (Alby, Joule) and autonomous agents (L402-Client/Aperture).
    - [x] Value-for-Value / Podcasting 2.0 streaming compatibility.
    - [x] Federated E-Cash evaluation (Fedi / Cashu / Fedimint).
- [x] **Streaming Analytics Dashboard**: Real-time admin widget tracking streamed Satoshis and micro-transactions generated by AI agents.

## Phase 10: UI Scalability & Advanced UX ✅

- [x] **Data Pagination**: Implement pagination for "Recent Activity Log" and "Billing History" to handle high-volume sites.
- [x] **Advanced Filtering**: Filter logs by bot type, protection applied, and text search (URI/Bot Name).
- [x] **Real-time Notifications**: Webhook or Email alerts for specific "High Intensity" bot activity.

## Phase 11: AI Engine Optimization (AEO) ✅

- [x] **JSON-LD @graph**: Entity-based connectivity (Organization, Author, Article) for improved AI grounding.
- [x] **FAQ Schema**: Automated detection of FAQ patterns for AI instant answers.
- [x] **AEO Discoverability**: Refined bot classification (AEO vs. Training) for optimal crawling permissions.
- [x] **Author Expertise (E-E-A-T)**: Structured social links and bio attribution for AI verification.
