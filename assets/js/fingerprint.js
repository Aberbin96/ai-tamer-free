(function() {
	if (window.aiTamerFingerprintRan) return;
	window.aiTamerFingerprintRan = true;

	var data = {
		webdriver: navigator.webdriver || false,
		chrome: !!window.chrome,
		plugins: navigator.plugins ? navigator.plugins.length : 0,
		mimeTypes: navigator.mimeTypes ? navigator.mimeTypes.length : 0,
		innerWidth: window.innerWidth,
		outerWidth: window.outerWidth,
		webgl: 'unknown'
	};

	try {
		var canvas = document.createElement('canvas');
		var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
		if (gl) {
			var ext = gl.getExtension('WEBGL_debug_renderer_info');
			if (ext) {
				data.webgl = gl.getParameter(ext.UNMASKED_RENDERER_WEBGL);
			}
		}
	} catch (e) {}

	// Only send if aiTamerApi is defined (injected via wp_localize_script)
	if (typeof aiTamerApi !== 'undefined' && aiTamerApi.root && aiTamerApi.nonce) {
		fetch(aiTamerApi.root + 'ai-tamer/v1/fingerprint', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': aiTamerApi.nonce
			},
			body: JSON.stringify(data),
			// Keepalive ensures it sends even if the user navigates away quickly
			keepalive: true
		}).catch(function() {});
	}
})();
