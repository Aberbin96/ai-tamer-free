# Technical Specifications

## AI Agent Identification
Detection relies on a combination of:
- **Known User-Agents**: Constantly updated strings for major AI bots.
- **Reverse DNS**: Verifying that a bot claiming to be Google really is.
- **IP Reputation**: Blocking known scraper farms.

## Metadata Implementation
The plugin automatically injects the following into every page:
```html
<meta name="robots" content="noai, noimageai">
```
And sends the corresponding HTTP headers:
```http
X-Robots-Tag: noai, noimageai
```

## Logging Strategy
To avoid database bloat and performance degradation:
- Logs are buffered and written asynchronously.
- We only log AI Agent activity, ignoring human visitors.
- Aggregation is performed daily to reduce storage footprint.
- **Auto-Cleanup**: Older logs are automatically purged to prevent disk saturation.

## Resource Protection (Rate Limiting)
Implementation of a "Leaky Bucket" or similar algorithm to:
- Monitor request frequency per IP/User-Agent.
- Trigger 429 (Too Many Requests) or 403 (Forbidden) for bots exceeding limits.
- Ensure low overhead on every request.

## Audit Reports
The system provides a downloadable audit trail:
- Format: CSV or PDF with cryptographic hashing (optional) for integrity.
- Content: Timestamp, AI Entity, Action taken, Target URI.

## License Declaration
AI Tamer declares the author's intent via machine-readable headers, providing a clear "No" to training data ingestion even if the bot ignores other signals.
