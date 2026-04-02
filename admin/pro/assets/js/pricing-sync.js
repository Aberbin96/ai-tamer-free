/**
 * AI Tamer Pricing Sync script.
 * Handles real-time satoshi conversion for fiat inputs.
 */
(function () {
	const fiatInput = document.getElementById("lnbits_pricing_fiat");
	const currencySelect = document.getElementById("lnbits_pricing_currency");
	const equivLabel = document.getElementById("aitamer-fiat-equiv");

	function calculateSats() {
		const lnxData = window.aitamerLN || { btc_rates: {} };
		const i18n = lnxData.i18n || {
			rate_missing:
				"Exchange rate unavailable — will use manual sats fallback.",
			approx_sats: "≈ %s sats (based on current exchange rate)",
		};

		if (!fiatInput || !currencySelect || !equivLabel) return;

		// Handle commas for decimals (e.g. 0,05 -> 0.05)
		const fiatRaw = fiatInput.value.replace(",", ".");
		const fiat = parseFloat(fiatRaw) || 0;
		const currency = currencySelect.value.toLowerCase();
		const rates = lnxData.btc_rates || {};
		const rate = rates[currency] ? parseFloat(rates[currency]) : 0;

		if (rate > 0 && fiat > 0) {
			const sats = Math.ceil((fiat / rate) * 100000000);
			// We no longer update a hidden sats input as PricingEngine handles it server-side.
			// But we show the label for user feedback.
			const text = i18n.approx_sats.replace("%s", sats.toLocaleString());
			equivLabel.textContent = text;
			equivLabel.style.color = "";
			equivLabel.style.opacity = "1";
		} else if (fiat > 0) {
			equivLabel.textContent = i18n.rate_missing;
			equivLabel.style.color = "#d63638";
			equivLabel.style.opacity = "1";
		}
	}

	// Attach listeners
	if (fiatInput) fiatInput.addEventListener("input", calculateSats);
	if (currencySelect)
		currencySelect.addEventListener("change", calculateSats);

	// Listen for rate updates from the polling widget
	window.addEventListener("aitamer_rates_updated", function (e) {
		if (e.detail && e.detail.rates && window.aitamerLN) {
			window.aitamerLN.btc_rates = e.detail.rates;
			calculateSats();
		}
	});

	// Initial run
	calculateSats();
})();
