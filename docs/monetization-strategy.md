# AI Tamer Monetization Strategy

This document outlines the evaluation of different monetization models for AI agents accessing WordPress content. Based on 2026 industry standards and bot behavior analysis, we evaluate three primary paths.

## 1. Bono de Lectura (Prepago) — Basado en Cantidad
El bot compra un pack (ej: "1.000 lecturas por 10€"). El plugin gestiona el saldo localmente y autoriza el acceso global o por volumen hasta agotar créditos.

- **Pros**:
    - **Evita el mínimo de Stripe (0.50€)**: Al agrupar miles de lecturas en una sola compra, la comisión es despreciable.
    - Zero per-reading transaction commissions.
- **Cons**:
    - **High Friction**: The AI agent must stop to purchase a new voucher once credits are exhausted.
- **Implementation Strategy**: Add a `credits` field to the `License` and track usage globally/by volume.

## 2. Monthly Aggregation (Batching)
The plugin records each reading as a "debt." A single consolidated invoice is issued at the end of the billing cycle (e.g., monthly).

- **Pros**:
    - **Fluid Experience**: No interruptions for the AI agent.
    - **Enterprise Friendly**: Large companies prefer monthly invoicing and PO-based billing.
- **Cons**:
    - **Risk of Non-payment**: A bot could consume thousands of articles and then fail to pay the invoice.
- **Implementation Strategy**: Use Stripe "Metered Billing" or local usage tracking synced to Stripe at intervals.

## 3. Micro-payments (L402 / Lightning)
Each request includes a digital payment (fractions of a cent) using the L402 protocol.

- **Pros**:
    - Real-time settlement.
    - No debt or upfront commitment.
    - Standard for advanced AI agents in 2026.
- **Cons**:
    - **Onboarding Friction**: Difficult for average WordPress users to configure "streaming wallets" or Lightning nodes.
- **Implementation Strategy**: Integration with L402-compatible gateways or custom headers (HTTP 402).

---

## UI/UX Strategic Planning (Admin Dashboard)

To align the WordPress admin experience with these models, we plan the following interface changes in the **Monetization** tab:

### 1. Strategic Advice Box (Header)
A high-visibility notification at the top of the settings page to guide the user:
- **V1: Blocking & Logs**: Control access and monitor bot behavior (Baseline).
- **V2: Reading Vouchers**: Recommended for most sites. Avoids the Stripe **0.50€ minimum transaction fee** by selling credits in bulk.
- **V3: Money Streaming**: The future of machine-to-machine payments (2027).

### 2. Field Renaming & Grouping
Existing technical fields will be renamed to provide better business context:
- **"Enable Micropayments"** → **"V1: Enable Single-Article Access"**.
    - *Note*: High friction for bots as they must stop for every article.
- **"Monthly Subscription Price ID"** → **"V2: Enterprise Subscription"**.
    - *Note*: Ideal for high-volume corporate partners.
- **New Section: "Bono de Lectura" (Vouchers)**:
    - Field for a "Pack of 1,000 Readings" Price ID.
    - *Note*: Most efficient for the site owner's cash flow.

### 3. Stripe Transaction Guard
Information tooltips will be added to explain that pricing below 0.50€ on Stripe is not viable due to fixed commissions, reinforcing the "Voucher" model as the primary monetization path for AI Tamer.
