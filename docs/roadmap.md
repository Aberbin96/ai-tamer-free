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
- [x] Integration with future licensing protocols (Web 3.0 / Digital Signatures).
- [x] Token Registry for manual license issuance and revocation.

> [!NOTE]
> All phases up to Phase 5 are complete. The following phases represent the future vision for the plugin and development has not yet started.

## Phase 6: Expanded Connectivity & Structured Data (v2) ✅

- [x] **RAG & Grounding Optimization**: Direct JSON/Markdown output for AI agents to prevent HTML noise.
- [x] **Machine-Readable Licenses**: Automated technical negotiation for bot access.
- [x] **Granular Media Control**: Selective blocking for image/video training (e.g., Midjourney/DALL-E) vs. text citation.
- [x] **Discovery Protocol (MCP)**: Implementation of Model Context Protocol for autonomous agent discovery (Endpoint `/catalog`).
- [x] **Smart Tolls**: Integration with payment gateways (Stripe) for per-query monetization.

## Phase 7: Active Defense & Origin Proof (v3) ✅

- [x] **Performance Fallback**: Multi-tier caching (Object Cache + Transients) for all environments.
- [x] **Advanced Auditing**: Enriched logs with IP, User-Agent, and protection metadata.
- [x] **Smart Fingerprinting**: Beyond UA matching—checking browser headers (`Sec-Fetch-*`).
- [x] **Data Poisoning**: Serving altered or "marked" content to hostile scrapers.
- [x] **C2PA Implementation**: Cryptographic "Proof of Human Origin" using the latest `DigitalDocument` / `CreativeWork` schema standards.
- [x] **IPTC Certification**: Manual "Human Origin" certification for media assets (IPTC 2:228) via the WordPress Media Library.
- [x] **Grammatical Watermarking**: Dual-layer invisible stylistic DNA to track content attribution in AI outputs.
- [x] **Micropayments (Protocol 402)**: Real-time billing for high-volume database access.
- [x] **Subscription-Linked Tokens**: Tokens validated against real-time Stripe subscription status.
- [x] **Scoped Access**: Granular control (Global vs. Post vs. Category).
- [x] **Billing History**: Integrated transaction log for AI license purchases and automated vouchers.
- [ ] **Stripe Return URLs**: Make success/cancel URLs configurable or dynamic for better integration (e.g., custom landing pages).

## Strategic Vision (2026-2027)

### V1: Control & Visibility (Complete ✅)

- [x] Advanced blocking, granular headers, and detailed logs.

### V2: Volume Licensing (Prepaid & Subscriptions) — Next Focus 🚀

- [ ] **Reading Vouchers**: Vouchers based on request count (e.g. 1k readings) to avoid Stripe transaction minimums.
- [ ] **Quantity-based Validation**: Tokens authorized by request quota rather than time/post scope.
- [ ] **Agent Balance API**: Allow bots to query their remaining credits via REST.

### V3: Real-time Streaming (Lightning/L402) — Future 🌐

- [ ] **Micro-payments per Post**: Settlement of fractions of a cent per request.
- [ ] **Frictionless Protocols**: L402 implementation for autonomous agents.

## Phase 10: UI Scalability & Advanced UX

- [ ] **Data Pagination**: Implement pagination for "Recent Activity Log" and "Billing History" to handle high-volume sites.
- [ ] **Advanced Filtering**: Filter logs by bot type, protection applied, or specific date ranges.
- [ ] **Real-time Notifications**: Webhook or Email alerts for specific "High Intensity" bot activity.
