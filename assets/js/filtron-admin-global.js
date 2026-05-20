(function () {
	'use strict';

	const copyText = async (text) => {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			try {
				await navigator.clipboard.writeText(text);
				return;
			} catch (error) {
				// Fall through to the textarea method for local HTTP/admin contexts.
			}
		}

		const ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', 'readonly');
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		document.execCommand('copy');
		document.body.removeChild(ta);
	};

	const setCopiedState = (button) => {
		const label = button.getAttribute('data-filtron-copied-label') || 'Copied';
		const original = button.getAttribute('data-filtron-copy-label') || 'Copy';
		const text = button.querySelector('.filtron-shortcode-copy__button-text');

		button.classList.add('is-copied');
		button.setAttribute('aria-live', 'polite');
		if (text) text.textContent = label;

		window.setTimeout(() => {
			button.classList.remove('is-copied');
			if (text) text.textContent = original;
		}, 1500);
	};

	document.addEventListener('click', (event) => {
		if (!event.target || !event.target.closest) return;

		const input = event.target.closest('.filtron-shortcode-copy__input');
		if (input) {
			input.select();
			return;
		}

		const button = event.target.closest('[data-filtron-copy-shortcode]');
		if (!button) return;

		event.preventDefault();
		const text = button.getAttribute('data-filtron-copy-shortcode') || '';
		if (!text) return;

		copyText(text)
			.then(() => setCopiedState(button))
			.catch(() => {
				const wrap = button.closest('.filtron-shortcode-copy');
				const input = wrap && wrap.querySelector('.filtron-shortcode-copy__input');
				if (input) {
					input.focus();
					input.select();
				}
			});
	});
})();
