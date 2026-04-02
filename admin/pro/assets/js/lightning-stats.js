/**
 * Lightning Streaming Analytics — real-time polling widget.
 *
 * Polls the /wp-json/ai-tamer/v1/lightning-stats endpoint every `pollInterval`
 * milliseconds and updates the dashboard card DOM in-place.
 *
 * Globals injected via wp_localize_script('aitamer-lightning-stats', 'aitamerLN', {...}):
 *   - rest_url      {string}  Full URL to the lightning-stats endpoint.
 *   - nonce         {string}  WordPress REST nonce.
 *   - poll_interval {number}  Polling interval in ms (default 30000).
 *   - i18n          {object}  Translatable strings.
 */
/* global aitamerLN */
(function () {
	'use strict';

	if (typeof aitamerLN === 'undefined') {
		return;
	}

	var cfg = aitamerLN;
	var interval = parseInt(cfg.poll_interval, 10) || 30000;
	var timerId = null;
	var lastUpdated = null;

	/* ---------- DOM refs (populated on DOMContentLoaded) ---------- */
	var el = {};

	function byId(id) {
		return document.getElementById(id);
	}

	function initRefs() {
		el.totalSats       = byId('aitlnx-total-sats');
		el.totalTx         = byId('aitlnx-total-tx');
		el.walletBalance   = byId('aitlnx-wallet-balance');
		el.btcRate         = byId('aitlnx-btc-rate');
		el.recentBody      = byId('aitlnx-recent-tbody');
		el.lastUpdated     = byId('aitlnx-last-updated');
		el.liveIndicator   = byId('aitlnx-live-dot');
		el.errorBadge      = byId('aitlnx-error-badge');
		el.refreshBtn      = byId('aitlnx-refresh-btn');
	}

	/* ---------- Helpers ---------- */

	function formatSats(n) {
		return Number(n).toLocaleString() + ' sats';
	}

	function formatBtcRate(rate, currency) {
		if (!rate) return '—';
		return currency.toUpperCase() + ' ' + Number(rate).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
	}

	function formatDate(iso) {
		if (!iso) return '—';
		try {
			var d = new Date(iso);
			return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		} catch (e) {
			return iso;
		}
	}

	function setLiveIndicator(active) {
		if (!el.liveIndicator) return;
		if (active) {
			el.liveIndicator.classList.add('aitlnx-pulsing');
			el.liveIndicator.title = cfg.i18n.polling || 'Live';
		} else {
			el.liveIndicator.classList.remove('aitlnx-pulsing');
		}
	}

	function showError(msg) {
		if (el.errorBadge) {
			el.errorBadge.textContent = msg || (cfg.i18n.error || 'Error');
			el.errorBadge.style.display = 'inline-block';
		}
	}

	function clearError() {
		if (el.errorBadge) {
			el.errorBadge.style.display = 'none';
		}
	}

	function updateTimestamp() {
		if (!el.lastUpdated) return;
		lastUpdated = new Date();
		el.lastUpdated.textContent = formatDate(lastUpdated.toISOString());
	}

	/* ---------- Data rendering ---------- */

	function renderStats(data) {
		clearError();

		if (el.totalSats) {
			el.totalSats.textContent = formatSats(data.total_sats_earned || 0);
		}
		if (el.totalTx) {
			el.totalTx.textContent = Number(data.total_transactions || 0).toLocaleString();
		}

		// Wallet balance.
		if (el.walletBalance) {
			var wb = data.lnbits_wallet;
			if (wb && wb.balance_sat !== undefined) {
				el.walletBalance.textContent = formatSats(wb.balance_sat);
				el.walletBalance.title = wb.name || '';
			} else {
				el.walletBalance.textContent = '—';
			}
		}

		// BTC rate.
		if (el.btcRate) {
			var rate = data.current_btc_rate;
			el.btcRate.textContent = formatBtcRate(rate ? rate.usd : null, 'USD');

			// [V3] Dispatch event so the settings form calculation can update if rate changed.
			if (rate) {
				window.dispatchEvent(new CustomEvent('aitamer_rates_updated', {
					detail: { rates: rate }
				}));
			}
		}

		// Recent transactions table.
		if (el.recentBody) {
			var txs = data.recent_transactions || [];
			if (txs.length === 0) {
				el.recentBody.innerHTML =
					'<tr><td colspan="4" style="text-align:center;color:#999;">' +
					(cfg.i18n.no_tx || 'No Lightning transactions yet.') +
					'</td></tr>';
			} else {
				var rows = '';
				for (var i = 0; i < txs.length; i++) {
					var tx = txs[i];
					var isSat = (tx.currency || '').toUpperCase() === 'SAT';
					var amount = isSat
						? formatSats(Math.round(tx.amount))
						: (tx.currency ? tx.currency.toUpperCase() + ' ' + Number(tx.amount).toFixed(2) : '—');
					var hash = tx.provider_id || '';
					// Strip the "ln_" prefix for display.
					var hashDisplay = hash.startsWith('ln_') ? hash.slice(3, 19) + '…' : hash.slice(0, 16) + '…';

					rows +=
						'<tr>' +
						'<td class="mono" style="white-space:nowrap;">' + escHtml(formatDate(tx.created_at)) + '</td>' +
						'<td>' + escHtml(tx.agent_name || '—') + '</td>' +
						'<td style="font-weight:600;color:#f7931a;">' + escHtml(amount) + '</td>' +
						'<td class="mono" title="' + escAttr(hash) + '">' + escHtml(hashDisplay) + '</td>' +
						'</tr>';
				}
				el.recentBody.innerHTML = rows;
			}
		}

		updateTimestamp();
	}

	/* ---------- Security helpers ---------- */

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escAttr(str) {
		return escHtml(str);
	}

	/* ---------- Fetch ---------- */

	function fetchStats() {
		setLiveIndicator(true);

		var xhr = new XMLHttpRequest();
		xhr.open('GET', cfg.rest_url, true);
		xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
		xhr.setRequestHeader('Accept', 'application/json');
		xhr.timeout = 15000;

		xhr.onload = function () {
			setLiveIndicator(false);
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					var data = JSON.parse(xhr.responseText);
					renderStats(data);
				} catch (e) {
					showError(cfg.i18n.parse_error || 'Parse error');
				}
			} else {
				showError((cfg.i18n.http_error || 'HTTP') + ' ' + xhr.status);
			}
		};

		xhr.onerror = function () {
			setLiveIndicator(false);
			showError(cfg.i18n.network_error || 'Network error');
		};

		xhr.ontimeout = function () {
			setLiveIndicator(false);
			showError(cfg.i18n.timeout || 'Timeout');
		};

		xhr.send();
	}

	/* ---------- Polling ---------- */

	function startPolling() {
		fetchStats(); // Immediate first fetch.
		timerId = setInterval(fetchStats, interval);
	}

	function handleRefreshClick(e) {
		e.preventDefault();
		clearInterval(timerId);
		startPolling();
	}

	/* ---------- Init ---------- */

	function init() {
		initRefs();
		if (!el.totalSats) {
			// Widget not present on this page.
			return;
		}
		if (el.refreshBtn) {
			el.refreshBtn.addEventListener('click', handleRefreshClick);
		}
		startPolling();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
