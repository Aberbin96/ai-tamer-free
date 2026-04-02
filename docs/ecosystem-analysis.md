# Ecosystem Compatibility Analysis

> AI Tamer Lightning & Monetization — Protocol Compatibility Report  
> Last updated: 2026-04-01

---

## 1. Browser Extensions

### 1.1 Alby (WebLN + L402)

| Aspect | Status | Notes |
|--------|--------|-------|
| **L402 Challenge** | ✅ Compatible | Alby reads `Www-Authenticate: L402 macaroon="...", invoice="..."` from 402 responses |
| **CORS** | ✅ Implemented | `Access-Control-Expose-Headers: Www-Authenticate, X-Payment-Link` allows JS `fetch()` to read the challenge |
| **Invoice Payment** | ✅ Compatible | Alby's WebLN `sendPayment()` handles BOLT11 invoices natively |
| **Auth Header** | ✅ Compatible | Alby sends `Authorization: L402 <macaroon>:<preimage>` after payment |
| **Auto-discovery** | ✅ Via `/license` | The `value4value` block in `/ai-tamer/v1/license` enables V4V-aware auto-discovery |

**Integration Flow:**
```
1. Agent/Browser requests GET /wp-json/ai-tamer/v1/content/{id}
2. AI Tamer returns 402 + Www-Authenticate header + l402 JSON body
3. Alby intercepts the 402, shows payment prompt with invoice
4. User approves → Alby pays BOLT11 → obtains preimage
5. Alby retries request with: Authorization: L402 <payment_hash>:<preimage>
6. AI Tamer validates payment → returns content
```

### 1.2 Joule (Legacy LSAT)

| Aspect | Status | Notes |
|--------|--------|-------|
| **LSAT Prefix** | ✅ Compatible | AI Tamer accepts both `LSAT ` and `L402 ` prefixes |
| **Challenge Format** | ✅ Compatible | Same `Www-Authenticate` header format |
| **Maintenance** | ⚠️ Deprecated | Joule is no longer actively maintained; migrating users should switch to Alby |

---

## 2. Autonomous Agents & Tools

### 2.1 lnget (Lightning Labs)

`lnget` is a CLI HTTP client that natively understands L402 challenges.

| Aspect | Status | Notes |
|--------|--------|-------|
| **402 Detection** | ✅ Compatible | Parses `Www-Authenticate: L402` header |
| **Auto-payment** | ✅ Compatible | Automatically pays the BOLT11 invoice via connected LND node |
| **Re-request** | ✅ Compatible | Retries with `Authorization: L402 <macaroon>:<preimage>` |

**Example usage:**
```bash
lnget --lnd-dir ~/.lnd GET https://example.com/wp-json/ai-tamer/v1/content/42
```

### 2.2 Aperture (Lightning Labs Reverse Proxy)

Aperture is a reverse proxy that sits in front of APIs and handles L402 challenge/response.

| Aspect | Status | Notes |
|--------|--------|-------|
| **Upstream compat** | ✅ Compatible | Aperture can proxy requests to AI Tamer; the 402 challenge is standard |
| **Macaroon baking** | ℹ️ Simplified | AI Tamer uses `payment_hash` as the macaroon identifier (LNbits flow) rather than LND-baked Macaroons. This is documented in the `l402.note` field of the 402 response |
| **Preimage verification** | ✅ Compatible | Payment verification via LNbits API |

### 2.3 Lightning Agent Tools (Lightning Labs)

Open-source toolkit for AI agents to interact with Lightning-enabled APIs.

| Aspect | Status | Notes |
|--------|--------|-------|
| **L402-aware requests** | ✅ Compatible | The toolkit reads `l402` metadata from the 402 JSON body |
| **`auth_header_format`** | ✅ Provided | AI Tamer includes the expected header format in the response |
| **Machine-readable pricing** | ✅ Provided | `price_sats`, `pricing.base_sats`, `pricing.multiplier` in 402 body |

---

## 3. Value-for-Value / Podcasting 2.0

### 3.1 Protocol Mapping

AI Tamer exposes a `value4value` block in the `/ai-tamer/v1/license` endpoint that follows the Podcasting 2.0 `<podcast:value>` specification adapted for JSON:

```json
{
  "value4value": {
    "type": "lightning",
    "method": "keysend",
    "suggested_amount_sats": 100,
    "recipients": [
      {
        "name": "Blog Name",
        "type": "wallet",
        "address": "<node_pubkey_66_hex>",
        "split": 100,
        "fee": false
      }
    ]
  }
}
```

**RSS `<podcast:value>` equivalent:**
```xml
<podcast:value type="lightning" method="keysend">
  <podcast:valueRecipient
    name="Blog Name"
    type="wallet"
    address="028f...a7c"
    split="100"
    fee="false" />
</podcast:value>
```

### 3.2 Compatible Apps

| App | Type | V4V Support | Keysend | Notes |
|-----|------|-------------|---------|-------|
| **Fountain** | Podcast Player | ✅ | ✅ | Could stream sats to content creators if RSS feed includes value tag |
| **Breez** | Wallet + Podcast | ✅ | ✅ | Supports Keysend natively |
| **Alby** | Browser Extension | ✅ | ✅ | Can auto-discover and send Keysend via WebLN |
| **Podverse** | Podcast Player | ✅ | ✅ | Open-source, V4V compatible |
| **Helipad** | Web Dashboard | ✅ | ✅ | Monitors incoming Keysend payments |

### 3.3 Requirements

For V4V to work, the site admin must configure their **Lightning Node Public Key** (66-char hex) in the AI Tamer Monetization settings. This key is used as the Keysend destination address. Without it, the `value4value` block is omitted from the license endpoint.

---

## 4. Federated E-Cash Protocols

### 4.1 Cashu

| Category | Assessment |
|----------|------------|
| **What it is** | A Chaumian e-cash protocol for Bitcoin. Users hold blinded tokens (e-cash) issued by a "mint" backed by Lightning |
| **Token format** | Cashu tokens are base64-encoded JSON containing proofs and mint URL |
| **Integration path** | A future `X-Cashu-Token` header could be accepted by AI Tamer. The server would verify the token against a trusted mint and redeem it for Lightning sats |
| **Complexity** | **Medium** — Requires trust in a specific mint and Cashu NUT (protocol spec) handling |
| **Privacy** | **Excellent** — Blinded tokens provide sender privacy |
| **Current status** | ℹ️ **Not implemented** — Extension point available via `aitamer_ecash_validate` filter |

**Recommendation**: Feasible as a Phase 2 addition. Cashu's token-passing model maps naturally to HTTP headers. A developer could implement a Cashu verifier plugin that hooks into `aitamer_ecash_validate`.

### 4.2 Fedimint

| Category | Assessment |
|----------|------------|
| **What it is** | Federated custody protocol using threshold signatures across multiple guardians |
| **Integration path** | Fedimint exposes a Lightning gateway — payments can be made over Lightning without direct Fedimint integration |
| **Complexity** | **High** — Requires running or connecting to a federation, which is overkill for a WordPress plugin |
| **WebSDK** | Available but designed for client-side federation wallets, not server-side payment verification |
| **Current status** | ❌ **Not recommended for direct integration** |

**Recommendation**: No direct plugin integration needed. Users can pay AI Tamer via their federation's Lightning gateway, which appears as a standard Lightning payment on the server side.

### 4.3 Fedi (Consumer Layer)

| Category | Assessment |
|----------|------------|
| **What it is** | Consumer app built atop Fedimint, providing a mobile wallet experience |
| **Integration path** | Fedi users pay via Lightning gateway → AI Tamer sees a standard L402 payment |
| **Complexity** | **None** — Transparent to the server |
| **Current status** | ✅ **Already compatible** — No changes needed |

**Recommendation**: No action required. Fedi users can pay L402 invoices through their Fedi wallet's Lightning gateway.

---

## 5. Extension Points

AI Tamer provides the following hooks for third-party integrations:

| Hook | Type | Purpose |
|------|------|---------|
| `aitamer_ecash_validate` | Filter | Validate alternative payment tokens (Cashu, custom e-cash). Return `true` to grant access |
| `aitamer_pricing_multiplier` | Filter | Adjust the dynamic pricing multiplier before applying to base price |
| `aitamer_base_price_sats` | Filter | Override the resolved base price in satoshis |

**Example Cashu integration (future plugin):**
```php
add_filter('aitamer_ecash_validate', function ($valid, $request) {
    $cashu_token = $request->get_header('X-Cashu-Token');
    if (empty($cashu_token)) return $valid;

    // Verify token against trusted mint...
    $mint_url = get_option('aitamer_cashu_mint_url');
    $result   = CashuVerifier::verify($cashu_token, $mint_url);

    return $result->is_valid();
}, 10, 2);
```

---

## 6. Summary

| Technology | Compatibility | Action Required |
|------------|---------------|-----------------|
| **Alby** | ✅ Full | None |
| **Joule** | ✅ Full (legacy) | None |
| **lnget** | ✅ Full | None |
| **Aperture** | ✅ Full | None |
| **Lightning Agent Tools** | ✅ Full | None |
| **V4V / Podcasting 2.0** | ✅ Implemented | Configure Node Pubkey in settings |
| **Cashu** | ⏳ Extension point ready | Future plugin via `aitamer_ecash_validate` |
| **Fedimint** | ✅ Via LN gateway | No plugin changes needed |
| **Fedi** | ✅ Via LN gateway | No plugin changes needed |
