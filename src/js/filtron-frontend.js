/**
 * Filtron — vanilla JS filter orchestration (no jQuery).
 * @package Filtron
 */
(function (global) {
	'use strict';

	const DEBOUNCE_SEARCH_MS = 300;
	const DEBOUNCE_RANGE_MS = 400;
	const RANGE_EPSILON = 0.0001;

	let filtronBodyLockDepth = 0;
	let filtronBodyPrevOverflow = '';
	let filtronDrawerEscBound = false;

	const filtronLockBody = () => {
		if (filtronBodyLockDepth === 0) {
			filtronBodyPrevOverflow = document.body.style.overflow;
			document.body.style.overflow = 'hidden';
			document.body.classList.add('filtron-drawer-open');
		}
		filtronBodyLockDepth++;
	};

	const filtronUnlockBody = () => {
		filtronBodyLockDepth = Math.max(0, filtronBodyLockDepth - 1);
		if (filtronBodyLockDepth === 0) {
			document.body.style.overflow = filtronBodyPrevOverflow;
			document.body.classList.remove('filtron-drawer-open');
		}
	};

	/** @type {Map<string, { collect?: (el: Element) => unknown|null, applyFromState?: (el: Element, row: unknown|null) => void }>} */
	const typeHandlers = new Map();

	/**
	 * @param {string} s
	 * @returns {string}
	 */
	const escHtml = (s) => {
		const d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	};

	/**
	 * @param {string} s
	 * @returns {string}
	 */
	const decodeHtmlEntities = (s) => {
		if (!s) return '';
		const t = document.createElement('textarea');
		t.innerHTML = String(s);
		return t.value;
	};

	/**
	 * @param {(...args: any[]) => void} fn
	 * @param {number} wait
	 */
	const debounce = (fn, wait) => {
		let t = 0;
		return (...args) => {
			clearTimeout(t);
			t = setTimeout(() => fn(...args), wait);
		};
	};

	/**
	 * @param {string} k "key|value" from PHP facet counts
	 * @returns {{ key: string, value: string }}
	 */
	const splitFacetKey = (k) => {
		const i = k.indexOf('|');
		if (i < 0) return { key: k, value: '' };
		return { key: k.slice(0, i), value: k.slice(i + 1) };
	};

	/**
	 * @param {Record<string, unknown>} row
	 * @returns {string}
	 */
	const rowId = (row) => {
		const t = String(row.type || '');
		const k = String(row.key || '');
		if (t === 'checkbox') return `checkbox:${k}`;
		if (t === 'range') return `range:${k}`;
		if (t === 'search') return `search:${k}`;
		if (t === 'swatch') return `swatch:${k}`;
		return `${t}:${k}`;
	};

	class FiltronCore {
		constructor() {
			/** @type {{ filters: Record<string, unknown>, page: number, loading: boolean }} */
			this.state = global.Filtron.state;
			/** @type {AbortController|null} */
			this._abort = null;
			/** @type {HTMLElement[]} */
			this._roots = [];
			/** @type {boolean} */
			this._mute = false;
			/** @type {boolean} */
			this._didHydrate = false;
			/** @type {ReturnType<typeof debounce>|null} */
			this._debCommitSearch = null;
			/** @type {WeakMap<Element, ReturnType<typeof debounce>>} */
			this._debRange = new WeakMap();
			/** @type {Record<string, unknown>} */
			this._cfg = {};
			/** @type {boolean} */
			this._isAppending = false;
			/** @type {Element|null} */
			this._drawerPrevFocus = null;
			/** @type {number} */
			this._drawerTouchStartX = 0;
		}

		/**
		 * @param {{ root: HTMLElement, grid?: string, chips?: string, clear?: string, perPage?: number, orderby?: string, order?: string, postType?: string, view?: string, onError?: (kind: 'network'|'server', err: unknown) => void }} cfg
		 */
		registerGroup(cfg) {
			const root = cfg.root;
			if (!this._roots.includes(root)) {
				this._roots.push(root);
			}
			this._cfg = {
				...this._cfg,
				...cfg,
				perPage: cfg.perPage || parseInt(root.getAttribute('data-filtron-per-page') || '0', 10) || undefined,
				orderby: cfg.orderby || root.getAttribute('data-filtron-orderby') || '',
				order: cfg.order || root.getAttribute('data-filtron-order') || '',
				postType: cfg.postType || root.getAttribute('data-filtron-post-type') || '',
				view: cfg.view || root.getAttribute('data-filtron-view') || 'grid',
			};
			this.applyThemeTokens(root);

			if (!this._debCommitSearch) {
				this._debCommitSearch = debounce(() => this.commitFromDom({ pushUrl: true }), DEBOUNCE_SEARCH_MS);
			}

			if (!this._didHydrate) {
				this.hydrateFromUrl();
				this._didHydrate = true;
			} else {
				this.collectRows(root).forEach((r) => {
					this.state.filters[rowId(r)] = r;
				});
			}

			this.bindWidgets(root, this._cfg);
			this.bindToolbar(root);
			this.bindPagination(root);
			this.bindLoadMore(root);
			this.bindMobileDrawer(root);
			this.applyToolbarFromCfg(root);
			this._roots.forEach((r) => this.applyDomFromState(r));
			if (this._roots[0]) this.renderChips(this._roots[0]);
			this.refreshResults();
		}

		/**
		 * Apply optional theme tokens from data attributes.
		 *
		 * Supported attrs:
		 * - data-filtron-accent
		 * - data-filtron-accent-2
		 * - data-filtron-accent-soft
		 * - data-filtron-accent-border
		 * - data-filtron-track
		 * - data-filtron-text-accent
		 * - data-filtron-price
		 * - data-filtron-rating
		 * - data-filtron-time
		 *
		 * @param {HTMLElement} root
		 */
		applyThemeTokens(root) {
			const map = {
				'data-filtron-accent': '--filtron-accent',
				'data-filtron-accent-2': '--filtron-accent-2',
				'data-filtron-accent-soft': '--filtron-accent-soft',
				'data-filtron-accent-border': '--filtron-accent-border',
				'data-filtron-track': '--filtron-track',
				'data-filtron-text-accent': '--filtron-text-accent',
				'data-filtron-price': '--filtron-price',
				'data-filtron-rating': '--filtron-rating',
				'data-filtron-time': '--filtron-time',
			};

			Object.entries(map).forEach(([attr, token]) => {
				const val = root.getAttribute(attr);
				if (val && val.trim()) {
					root.style.setProperty(token, val.trim());
				}
			});
		}

		/**
		 * @param {HTMLElement} root
		 */
		applyToolbarFromCfg(root) {
			const toolbar = root.querySelector('[data-filtron-toolbar="1"]');
			if (!toolbar) return;
			const mode = String(this._cfg.view || 'grid');
			const sortVal = `${String(this._cfg.orderby || 'date')}:${String(this._cfg.order || 'DESC')}`;
			const sort = toolbar.querySelector('[data-filtron-sort="1"]');
			if (sort) {
				sort.value = sortVal;
			}
			toolbar.querySelectorAll('[data-filtron-view]').forEach((b) => {
				const thisMode = b.getAttribute('data-filtron-view') || 'grid';
				const on = thisMode === mode;
				b.classList.toggle('is-active', on);
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			const sel = root.getAttribute('data-filtron-grid') || '#filtron-results';
			const grid = document.querySelector(sel);
			if (grid) {
				grid.classList.toggle('filtron-results--list', mode === 'list');
			}
		}

		/**
		 * @param {HTMLElement} root
		 */
		bindToolbar(root) {
			const toolbar = root.querySelector('[data-filtron-toolbar="1"]');
			if (!toolbar || toolbar.dataset.filtronToolbarBound) return;
			toolbar.dataset.filtronToolbarBound = '1';

			const sort = toolbar.querySelector('[data-filtron-sort="1"]');
			if (sort) {
				const existing = `${String(this._cfg.orderby || 'date')}:${String(this._cfg.order || 'DESC')}`;
				sort.value = sort.value || existing;
				sort.addEventListener('change', () => {
					const raw = String(sort.value || 'date:DESC');
					const [orderby, order] = raw.split(':');
					this._cfg.orderby = (orderby || 'date').trim();
					this._cfg.order = (order || 'DESC').trim();
					this.state.page = 1;
					this.syncUrl();
					this.refreshResults();
				});
			}

			toolbar.addEventListener('click', (e) => {
				const btn = e.target && /** @type {HTMLElement} */ (e.target).closest('[data-filtron-view]');
				if (!btn || !toolbar.contains(btn)) return;
				e.preventDefault();
				const mode = String(btn.getAttribute('data-filtron-view') || 'grid');
				this._cfg.view = mode;
				this._roots.forEach((r) => {
					const sel = r.getAttribute('data-filtron-grid') || '#filtron-results';
					const grid = document.querySelector(sel);
					if (!grid) return;
					grid.classList.toggle('filtron-results--list', mode === 'list');
				});
				toolbar.querySelectorAll('[data-filtron-view]').forEach((b) => {
					const on = b === btn;
					b.classList.toggle('is-active', on);
					b.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				this.syncUrl();
			});

			const viewsGroup = toolbar.querySelector('.filtron-toolbar__views');
			const viewButtons = () => Array.from(toolbar.querySelectorAll('[data-filtron-view]'));
			if (viewsGroup && !viewsGroup.dataset.filtronViewKeysBound) {
				viewsGroup.dataset.filtronViewKeysBound = '1';
				viewsGroup.addEventListener('keydown', (e) => {
					if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
					const btns = viewButtons();
					if (btns.length < 2) return;
					const i = btns.indexOf(/** @type {HTMLElement} */ (document.activeElement));
					if (i < 0) return;
					e.preventDefault();
					const next = e.key === 'ArrowRight' ? (i + 1) % btns.length : (i - 1 + btns.length) % btns.length;
					btns[next].focus();
				});
			}
		}

		/**
		 * @param {HTMLElement} root
		 */
		bindPagination(root) {
			const holder = root.querySelector('[data-filtron-pagination="1"]');
			if (!holder || holder.dataset.filtronPaginationBound) return;
			holder.dataset.filtronPaginationBound = '1';
			holder.addEventListener('click', (e) => {
				const prev = e.target && /** @type {HTMLElement} */ (e.target).closest('[data-filtron-page-prev]');
				const next = e.target && /** @type {HTMLElement} */ (e.target).closest('[data-filtron-page-next]');
				if (!prev && !next) return;
				e.preventDefault();
				if (prev) {
					this.state.page = Math.max(1, this.state.page - 1);
				} else if (next) {
					this.state.page += 1;
				}
				this._isAppending = false;
				this.syncUrl();
				this.refreshResults();
			});
		}

		/**
		 * @param {HTMLElement} root
		 */
		bindLoadMore(root) {
			const btn = root.querySelector('[data-filtron-load-more="1"]');
			if (!btn || btn.dataset.filtronLoadMoreBound) return;
			btn.dataset.filtronLoadMoreBound = '1';
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				this.state.page += 1;
				this._isAppending = true;
				this.syncUrl();
				this.refreshResults();
			});
		}

		/**
		 * @param {HTMLElement} root
		 * @param {{ chips?: string, clear?: string, onError?: (a: 'network'|'server', b: unknown) => void }} cfg
		 */
		bindWidgets(root, cfg) {
			const self = this;
			const opt = cfg || this._cfg || {};
			const widgets = root.querySelectorAll('[data-filtron-type][data-filtron-key]');
			widgets.forEach((w) => {
				const type = (w.getAttribute('data-filtron-type') || '').toLowerCase();
				if (type === 'checkbox') {
					w.querySelectorAll('input.filtron-filter-checkbox__input[type="checkbox"]').forEach((inp) => {
						inp.addEventListener('change', () => self.commitFromDom({ pushUrl: true }));
					});
				} else if (type === 'select') {
					const select = w.querySelector('.filtron-filter-select__input');
					if (select) select.addEventListener('change', () => self.commitFromDom({ pushUrl: true }));
				} else if (type === 'range') {
					self.initRange(/** @type {HTMLElement} */ (w), opt);
				} else if (type === 'search') {
					const input = w.querySelector('.filtron-filter-search__input');
					if (input) {
						input.addEventListener('input', () => self._debCommitSearch && self._debCommitSearch());
						self.initSearchSuggest(/** @type {HTMLElement} */ (w));
					}
				}
			});

			const chipsSel = opt.chips || root.getAttribute('data-filtron-chips') || '';
			const chipsMount =
				(chipsSel && document.querySelector(chipsSel)) ||
				root.querySelector('.filtron-active-chips') ||
				null;
			const chipMount = chipsMount || root;
			if (!chipMount.dataset.filtronChipsBound) {
				chipMount.dataset.filtronChipsBound = '1';
				chipMount.addEventListener('click', (e) => {
					const t = e.target && /** @type {HTMLElement} */ (e.target).closest('[data-filtron-chip-remove]');
					if (!t || !chipMount.contains(t)) return;
					e.preventDefault();
					const typ = t.getAttribute('data-filtron-chip-type');
					const key = t.getAttribute('data-filtron-chip-key');
					const val = t.getAttribute('data-filtron-chip-value');
					self.removeChipFilter(typ, key, val);
					self._roots.forEach((r) => self.applyDomFromState(r));
					if (self._roots[0]) self.renderChips(self._roots[0]);
					self.syncUrl();
					self.refreshResults();
				});
			}

			const clearSel = opt.clear || root.getAttribute('data-filtron-clear') || '';
			const clearBtn =
				(clearSel && document.querySelector(clearSel)) ||
				root.querySelector('.filtron-clear-all:not(.filtron-clear-all--inline)');
			if (clearBtn && !clearBtn.dataset.filtronClearBound) {
				clearBtn.dataset.filtronClearBound = '1';
				clearBtn.addEventListener('click', (e) => {
					e.preventDefault();
					self.clearAllFilters();
					self._roots.forEach((r) => self.applyDomFromState(r));
					if (self._roots[0]) self.renderChips(self._roots[0]);
					self.syncUrl();
					self.refreshResults();
				});
			}

			self.bindEmptyStateActions(root);
		}

		/**
		 * @param {HTMLElement} root
		 */
		bindEmptyStateActions(root) {
			if (root.dataset.filtronEmptyBound === '1') return;
			root.dataset.filtronEmptyBound = '1';
			const self = this;
			root.addEventListener('click', (e) => {
				const c = e.target && /** @type {HTMLElement} */ (e.target).closest('[data-filtron-empty-clear]');
				const r = e.target && /** @type {HTMLElement} */ (e.target).closest('[data-filtron-empty-reset-range]');
				if (!c && !r) return;
				if (!root.contains(c || r)) return;
				e.preventDefault();
				if (c) {
					self.clearAllFilters();
					self._roots.forEach((rr) => self.applyDomFromState(rr));
					if (self._roots[0]) self.renderChips(self._roots[0]);
					self.syncUrl();
					self.refreshResults();
					return;
				}
				if (r) {
					self.resetAllRangesInDom();
					self.commitFromDom({ pushUrl: true });
				}
			});
		}

		resetAllRangesInDom() {
			this._roots.forEach((root) => {
				root.querySelectorAll('[data-filtron-type="range"]').forEach((wrap) => {
					const el = /** @type {HTMLElement} */ (wrap);
					const min = parseFloat(el.getAttribute('data-filtron-min') || '0', 10);
					const max = parseFloat(el.getAttribute('data-filtron-max') || '0', 10);
					const track = el.querySelector('.filtron-filter-range__track');
					if (track && track.noUiSlider && typeof track.noUiSlider.set === 'function') {
						track.noUiSlider.set([min, max]);
					}
					const fmin = el.querySelector('.filtron-filter-range__fallback-min');
					const fmax = el.querySelector('.filtron-filter-range__fallback-max');
					if (fmin) /** @type {HTMLInputElement} */ (fmin).value = String(min);
					if (fmax) /** @type {HTMLInputElement} */ (fmax).value = String(max);
					this.updateRangeReadout(el, min, max);
				});
			});
		}

		clearAllFilters() {
			this.state.filters = {};
			this.state.page = 1;
		}

		/**
		 * @param {string|null} typ
		 * @param {string|null} key
		 * @param {string|null} val
		 */
		removeChipFilter(typ, key, val) {
			if (!key || !typ) return;
			const id = `${typ}:${key}`;
			const row = this.state.filters[id];
			if (!row || typeof row !== 'object') return;
			if ((typ === 'checkbox' || typ === 'select') && val != null) {
				const values = Array.isArray(row.values) ? row.values.filter((v) => String(v) !== String(val)) : [];
				if (values.length === 0) delete this.state.filters[id];
				else row.values = values;
			} else {
				delete this.state.filters[id];
			}
			this.state.page = 1;
		}

		/**
		 * @param {{ onError?: (a: 'network'|'server', b: unknown) => void }} cfg
		 */
		initRange(wrap, cfg) {
			const track = wrap.querySelector('.filtron-filter-range__track');
			const ns = global.noUiSlider;
			if (!track) return;
			if (!ns || typeof ns.create !== 'function') {
				this.initRangeFallback(wrap);
				return;
			}
			if (track.noUiSlider) return;

			const min = parseFloat(wrap.getAttribute('data-filtron-min') || '0', 10);
			const max = parseFloat(wrap.getAttribute('data-filtron-max') || '0', 10);
			const step = parseFloat(wrap.getAttribute('data-filtron-step') || '1', 10) || 1;
			const cmin = parseFloat(wrap.getAttribute('data-filtron-current-min') || String(min), 10);
			const cmax = parseFloat(wrap.getAttribute('data-filtron-current-max') || String(max), 10);

			ns.create(track, {
				start: [cmin, cmax],
				connect: true,
				step,
				range: { min, max },
			});

			let deb = this._debRange.get(track);
			if (!deb) {
				const self = this;
				deb = debounce(() => {
					if (!track.noUiSlider) return;
					const vals = track.noUiSlider.get();
					const a = parseFloat(vals[0], 10);
					const b = parseFloat(vals[1], 10);
					self.updateRangeReadout(wrap, a, b);
					self.commitFromDom({ pushUrl: true });
				}, DEBOUNCE_RANGE_MS);
				this._debRange.set(track, deb);
			}

			const self = this;
			track.noUiSlider.on('update', () => {
				if (!track.noUiSlider) return;
				const vals = track.noUiSlider.get();
				const a = parseFloat(vals[0], 10);
				const b = parseFloat(vals[1], 10);
				self.updateRangeReadout(wrap, a, b);
				deb && deb();
			});
		}

		/**
		 * Fallback dual input range controls when noUiSlider is missing.
		 *
		 * @param {HTMLElement} wrap
		 */
		initRangeFallback(wrap) {
			if (wrap.dataset.filtronRangeFallbackBound === '1') return;

			const min = parseFloat(wrap.getAttribute('data-filtron-min') || '0', 10);
			const max = parseFloat(wrap.getAttribute('data-filtron-max') || '0', 10);
			const step = parseFloat(wrap.getAttribute('data-filtron-step') || '1', 10) || 1;
			let cmin = parseFloat(wrap.getAttribute('data-filtron-current-min') || String(min), 10);
			let cmax = parseFloat(wrap.getAttribute('data-filtron-current-max') || String(max), 10);
			if (cmin > cmax) {
				const t = cmin;
				cmin = cmax;
				cmax = t;
			}

			const holder = document.createElement('div');
			holder.className = 'filtron-filter-range__fallback';

			const inMin = document.createElement('input');
			inMin.type = 'range';
			inMin.className = 'filtron-filter-range__fallback-min';
			inMin.min = String(min);
			inMin.max = String(max);
			inMin.step = String(step);
			inMin.value = String(cmin);

			const inMax = document.createElement('input');
			inMax.type = 'range';
			inMax.className = 'filtron-filter-range__fallback-max';
			inMax.min = String(min);
			inMax.max = String(max);
			inMax.step = String(step);
			inMax.value = String(cmax);

			holder.appendChild(inMin);
			holder.appendChild(inMax);
			wrap.appendChild(holder);

			const commit = debounce(() => this.commitFromDom({ pushUrl: true }), DEBOUNCE_RANGE_MS);
			const onChange = () => {
				let a = parseFloat(inMin.value, 10);
				let b = parseFloat(inMax.value, 10);
				if (a > b) {
					if (document.activeElement === inMin) {
						b = a;
						inMax.value = String(b);
					} else {
						a = b;
						inMin.value = String(a);
					}
				}
				this.updateRangeReadout(wrap, a, b);
				commit();
			};

			inMin.addEventListener('input', onChange);
			inMax.addEventListener('input', onChange);

			wrap.dataset.filtronRangeFallbackBound = '1';
			this.updateRangeReadout(wrap, cmin, cmax);
		}

		/**
		 * @param {HTMLElement} wrap
		 * @param {number} a
		 * @param {number} b
		 */
		updateRangeReadout(wrap, a, b) {
			const rmin = wrap.querySelector('.filtron-filter-range__readout-min');
			const rmax = wrap.querySelector('.filtron-filter-range__readout-max');
			if (rmin) rmin.textContent = String(a);
			if (rmax) rmax.textContent = String(b);
			wrap.setAttribute('data-filtron-current-min', String(a));
			wrap.setAttribute('data-filtron-current-max', String(b));
		}

		/**
		 * @param {HTMLElement} wrap
		 */
		initSearchSuggest(wrap) {
			const input = wrap.querySelector('.filtron-filter-search__input');
			const drop = wrap.querySelector('.filtron-filter-search__dropdown');
			const list = wrap.querySelector('.filtron-filter-search__list');
			const empty = wrap.querySelector('.filtron-filter-search__empty');
			const ajaxUrl = wrap.getAttribute('data-filtron-ajax-url') || (global.filtronVars && global.filtronVars.ajax_url) || '';
			const nonce = wrap.getAttribute('data-filtron-nonce') || (global.filtronVars && global.filtronVars.nonce) || '';
			const action = wrap.getAttribute('data-filtron-action') || 'filtron_search_suggest';
			const fkey = wrap.getAttribute('data-filtron-key') || '';
			const minC = parseInt(wrap.getAttribute('data-filtron-min-chars') || '2', 10) || 2;
			if (!input || !drop || !list || !ajaxUrl || !nonce || !fkey) return;

			let active = -1;
			let items = [];

			const closeList = () => {
				drop.hidden = true;
				list.innerHTML = '';
				if (empty) empty.hidden = true;
				active = -1;
				items = [];
				input.setAttribute('aria-expanded', 'false');
			};

			const openList = () => {
				drop.hidden = false;
				input.setAttribute('aria-expanded', 'true');
			};

			const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			const hi = (title, q) => {
				if (!q || !title) return escHtml(title);
				const re = new RegExp(escapeRe(q), 'ig');
				let out = '';
				let last = 0;
				let m;
				while ((m = re.exec(title)) !== null) {
					out += escHtml(title.slice(last, m.index));
					out += '<mark>' + escHtml(m[0]) + '</mark>';
					last = m.index + m[0].length;
					if (last === m.index) re.lastIndex++;
				}
				out += escHtml(title.slice(last));
				return out;
			};

			const renderItems = (rows, q) => {
				list.innerHTML = '';
				if (!rows || !rows.length) {
					if (empty) empty.hidden = false;
					return;
				}
				if (empty) empty.hidden = true;
				rows.forEach((row) => {
					const li = document.createElement('li');
					li.setAttribute('role', 'option');
					li.className = 'filtron-filter-search__option';
					li.setAttribute('data-title', row.title || '');
					li.innerHTML = hi(row.title || '', q);
					li.addEventListener('mousedown', (e) => {
						e.preventDefault();
						input.value = li.getAttribute('data-title') || '';
						closeList();
					});
					list.appendChild(li);
				});
				items = Array.prototype.slice.call(list.querySelectorAll('.filtron-filter-search__option'));
			};

			const debFetch = debounce(() => {
				const q = (input.value || '').trim();
				if (q.length < minC) {
					closeList();
					return;
				}
				const body = new URLSearchParams();
				body.set('action', action);
				body.set('nonce', nonce);
				body.set('filter_key', fkey);
				body.set('q', q);
				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString(),
				})
					.then((r) => r.json())
					.then((data) => {
						if (!data || !data.success) {
							renderItems([], q);
							openList();
							return;
						}
						const payload = data.data || {};
						renderItems(payload.items || [], payload.q || q);
						openList();
					})
					.catch(() => {
						renderItems([], q);
						openList();
					});
			}, DEBOUNCE_SEARCH_MS);

			input.addEventListener('input', () => debFetch());

			input.addEventListener('keydown', (e) => {
				if (!drop.hidden) {
					if (e.key === 'Escape') {
						e.preventDefault();
						closeList();
						return;
					}
					if (items.length) {
						if (e.key === 'ArrowDown') {
							e.preventDefault();
							active = Math.min(active + 1, items.length - 1);
							items.forEach((el, i) => el.setAttribute('aria-selected', i === active ? 'true' : 'false'));
						} else if (e.key === 'ArrowUp') {
							e.preventDefault();
							active = Math.max(active - 1, -1);
							items.forEach((el, i) => el.setAttribute('aria-selected', i === active ? 'true' : 'false'));
						} else if (e.key === 'Enter' && active >= 0) {
							e.preventDefault();
							const el = items[active];
							input.value = el.getAttribute('data-title') || '';
							closeList();
						}
					}
				}
			});

			document.addEventListener('click', (ev) => {
				if (!wrap.contains(/** @type {Node} */ (ev.target))) closeList();
			});
		}

		/**
		 * @param {{ pushUrl?: boolean }} opt
		 */
		commitFromDom(opt) {
			if (this._mute) return;
			const rows = this.collectAllRows();
			this.state.filters = {};
			rows.forEach((r) => {
				this.state.filters[rowId(r)] = r;
			});
			this.state.page = 1;
			this._isAppending = false;
			if (this._roots[0]) this.renderChips(this._roots[0]);
			if (opt.pushUrl) this.syncUrl();
			this.refreshResults();
		}

		hydrateFromUrl() {
			const parsed = urlToState(global.location.href);
			this.state.filters = parsed.filters;
			this.state.page = parsed.page;
			if (parsed.orderby) this._cfg.orderby = parsed.orderby;
			if (parsed.order) this._cfg.order = parsed.order;
			if (parsed.view) this._cfg.view = parsed.view;
		}

		syncUrl(opt = {}) {
			const next = filterToURL(this.state, global.location.href, {
				orderby: this._cfg.orderby || '',
				order: this._cfg.order || '',
				view: this._cfg.view || 'grid',
			});
			const current = new URL(global.location.href, global.location.origin).toString();
			if (next === current) return;
			if (opt && opt.replace) {
				global.history.replaceState({ filtron: true }, '', next);
				return;
			}
			global.history.pushState({ filtron: true }, '', next);
		}

		/**
		 * @param {HTMLElement} root
		 */
		applyDomFromState(root) {
			this._mute = true;
			try {
				const rows = Object.values(this.state.filters);
				applyRowsToDom(root, rows);
			} finally {
				this._mute = false;
			}
		}

		/**
		 * @param {HTMLElement} root
		 */
		renderChips(root) {
			let host =
				root.querySelector('.filtron-active-chips') ||
				/** @type {HTMLElement|null} */ (root.querySelector('[data-filtron-chips-inner]'));
			if (!host) {
				host = document.createElement('div');
				host.className = 'filtron-active-chips';
				host.setAttribute('data-filtron-chips-inner', '1');
				root.insertBefore(host, root.firstChild);
			}
			const rows = Object.values(this.state.filters);
			const i18n = (global.filtronVars && global.filtronVars.i18n) || {};
			const parts = [];
			for (const row of rows) {
				if (!row || typeof row !== 'object') continue;
				const t = String(row.type);
				if (t === 'checkbox' && Array.isArray(row.values)) {
					for (const v of row.values) {
						const chipAria = escHtml(
							String(i18n.chipRemoveCheckbox || 'Remove filter %1$s equals %2$s')
								.replace('%1$s', String(row.key))
								.replace('%2$s', String(v))
						);
						parts.push(
							`<button type="button" class="filtron-chip" data-filtron-chip-remove="1" data-filtron-chip-type="checkbox" data-filtron-chip-key="${escHtml(
								String(row.key)
							)}" data-filtron-chip-value="${escHtml(String(v))}" aria-label="${chipAria}"><span class="filtron-chip__text">${escHtml(
								String(v)
							)}</span><span class="filtron-chip__x" aria-hidden="true">&times;</span></button>`
						);
					}
				} else if (t === 'select' && Array.isArray(row.values) && row.values.length) {
					const v = String(row.values[0]);
					const chipAria = escHtml(
						String(i18n.chipRemoveSelect || i18n.chipRemoveCheckbox || 'Remove filter %1$s equals %2$s')
							.replace('%1$s', String(row.key))
							.replace('%2$s', v)
					);
					parts.push(
						`<button type="button" class="filtron-chip" data-filtron-chip-remove="1" data-filtron-chip-type="select" data-filtron-chip-key="${escHtml(
							String(row.key)
						)}" data-filtron-chip-value="${escHtml(v)}" aria-label="${chipAria}"><span class="filtron-chip__text">${escHtml(
							v
						)}</span><span class="filtron-chip__x" aria-hidden="true">&times;</span></button>`
					);
				} else if (t === 'range') {
					if (!this.isRangeRowActive(row)) continue;
					const chipAria = escHtml(
						String(i18n.chipRemoveRange || 'Remove range filter: %s').replace('%s', String(row.key))
					);
					parts.push(
						`<button type="button" class="filtron-chip" data-filtron-chip-remove="1" data-filtron-chip-type="range" data-filtron-chip-key="${escHtml(
							String(row.key)
						)}" aria-label="${chipAria}"><span class="filtron-chip__text">${escHtml(String(row.key))}: ${escHtml(String(row.min))}&ndash;${escHtml(
							String(row.max)
						)}</span><span class="filtron-chip__x" aria-hidden="true">&times;</span></button>`
					);
				} else if (t === 'search') {
					const chipAria = escHtml(
						String(i18n.chipRemoveSearch || 'Remove search filter: %s').replace('%s', String(row.value || ''))
					);
					parts.push(
						`<button type="button" class="filtron-chip" data-filtron-chip-remove="1" data-filtron-chip-type="search" data-filtron-chip-key="${escHtml(
							String(row.key)
						)}" aria-label="${chipAria}"><span class="filtron-chip__text">${escHtml(String(row.value || ''))}</span><span class="filtron-chip__x" aria-hidden="true">&times;</span></button>`
					);
				}
			}
			const clearLabel =
				(global.filtronVars && global.filtronVars.i18n && global.filtronVars.i18n.clearAll) || 'Clear all';
			const clearAllAria = escHtml(String(i18n.clearFilters || clearLabel));
			host.innerHTML =
				parts.join('') +
				(parts.length
					? ` <button type="button" class="filtron-clear-all filtron-clear-all--inline" aria-label="${clearAllAria}">${escHtml(clearLabel)}</button>`
					: '');
			const inlineClear = host.querySelector('.filtron-clear-all--inline');
			if (inlineClear) {
				const self = this;
				inlineClear.addEventListener('click', (e) => {
					e.preventDefault();
					self.clearAllFilters();
					self._roots.forEach((r) => self.applyDomFromState(r));
					if (self._roots[0]) self.renderChips(self._roots[0]);
					self.syncUrl();
					self.refreshResults();
				});
			}
			this._roots.forEach((r) => this.updateDrawerUi(r));
		}

		/**
		 * @param {Record<string, unknown>[]} rows
		 * @returns {number}
		 */
		countActiveSelections(rows) {
			let count = 0;
			for (const row of rows) {
				if (!row || typeof row !== 'object') continue;
				const t = String(row.type || '');
				if (t === 'checkbox' && Array.isArray(row.values)) {
					count += row.values.length;
					continue;
				}
				if (t === 'range') {
					if (this.isRangeRowActive(row)) count += 1;
					continue;
				}
				if (t === 'select' && Array.isArray(row.values)) {
					if (row.values.length > 0) count += 1;
					continue;
				}
				if (t === 'search' || t === 'swatch') {
					count += 1;
				}
			}
			return count;
		}

		/**
		 * @param {HTMLElement} root
		 */
		updateDrawerUi(root) {
			const badge = root.querySelector('[data-filtron-filter-badge="1"]');
			const openBtn = root.querySelector('[data-filtron-open-drawer="1"]');
			const meta = root.querySelector('[data-filtron-drawer-meta="1"]');
			if (!badge && !openBtn && !meta) return;
			const i18n = (global.filtronVars && global.filtronVars.i18n) || {};
			const rows = Object.values(this.state.filters);
			const n = this.countActiveSelections(rows);
			if (badge) {
				badge.textContent = String(n);
				const hide = n <= 0;
				badge.hidden = hide;
				badge.setAttribute('aria-hidden', hide ? 'true' : 'false');
			}
			const baseLabel = String(i18n.filters || 'Filters');
			const activeTpl = String(i18n.activeCount || '%s active');
			if (openBtn) {
				openBtn.setAttribute('aria-label', n > 0 ? `${baseLabel}, ${activeTpl.replace('%s', String(n))}` : baseLabel);
			}
			let safe = 0;
			const metaCountEl = root.querySelector('.filtron-summary__result-count');
			if (metaCountEl) {
				const parsed = parseInt(String(metaCountEl.textContent || '0'), 10);
				if (Number.isFinite(parsed)) safe = parsed;
			}
			if (meta) {
				const tpl = String(i18n.resultsCount || '%s results');
				meta.textContent = tpl.replace('%s', String(safe));
			}
		}

		/**
		 * @param {HTMLElement} root
		 */
		closeMobileDrawer(root) {
			const panel = root.querySelector('[data-filtron-offcanvas="1"]');
			const overlay = root.querySelector('[data-filtron-overlay="1"]');
			const openBtn = root.querySelector('[data-filtron-open-drawer="1"]');
			if (!panel || !panel.classList.contains('is-open')) return;
			panel.classList.remove('is-open');
			if (overlay) overlay.classList.remove('is-visible');
			panel.setAttribute('aria-hidden', 'true');
			if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
			filtronUnlockBody();
			const pf = this._drawerPrevFocus;
			this._drawerPrevFocus = null;
			if (pf && /** @type {any} */ (pf).focus) {
				try {
					/** @type {HTMLElement} */ (/** @type {unknown} */ (pf)).focus();
				} catch (_) {
					/* ignore */
				}
			} else if (openBtn) {
				openBtn.focus();
			}
		}

		/**
		 * @param {HTMLElement} keep
		 */
		closeOtherMobileDrawers(keep) {
			document.querySelectorAll('.filtron-offcanvas.is-open').forEach((p) => {
				const r = p.closest('[data-filtron-group]');
				if (r && r !== keep) this.closeMobileDrawer(/** @type {HTMLElement} */ (r));
			});
		}

		/**
		 * @param {HTMLElement} root
		 */
		openMobileDrawer(root) {
			const panel = root.querySelector('[data-filtron-offcanvas="1"]');
			const overlay = root.querySelector('[data-filtron-overlay="1"]');
			const openBtn = root.querySelector('[data-filtron-open-drawer="1"]');
			if (!panel || panel.classList.contains('is-open')) return;
			this.closeOtherMobileDrawers(root);
			this._drawerPrevFocus = /** @type {Element|null} */ (document.activeElement);
			panel.classList.add('is-open');
			if (overlay) overlay.classList.add('is-visible');
			panel.setAttribute('aria-hidden', 'false');
			if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
			filtronLockBody();
			const closeBtn = panel.querySelector('[data-filtron-close-drawer="1"]');
			if (closeBtn && /** @type {any} */ (closeBtn).focus) {
				/** @type {HTMLElement} */ (closeBtn).focus();
			}
		}

		/**
		 * @param {HTMLElement} root
		 */
		bindMobileDrawer(root) {
			const panel = root.querySelector('[data-filtron-offcanvas="1"]');
			const overlay = root.querySelector('[data-filtron-overlay="1"]');
			const openBtn = root.querySelector('[data-filtron-open-drawer="1"]');
			if (!panel || !overlay || !openBtn) return;
			if (root.getAttribute('data-filtron-drawer-bound') === '1') return;
			root.setAttribute('data-filtron-drawer-bound', '1');

			const self = this;
			const closeBtn = panel.querySelector('[data-filtron-close-drawer="1"]');
			const applyBtn = panel.querySelector('[data-filtron-apply-drawer="1"]');
			const close = () => self.closeMobileDrawer(root);
			const open = () => self.openMobileDrawer(root);

			openBtn.addEventListener('click', open);
			if (closeBtn) closeBtn.addEventListener('click', close);
			if (applyBtn) {
				applyBtn.addEventListener('click', () => {
					close();
					const main = root.querySelector('.filtron-widget__main');
					if (main && typeof main.scrollIntoView === 'function') {
						main.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
				});
			}
			overlay.addEventListener('click', close);

			panel.addEventListener(
				'touchstart',
				(e) => {
					if (e.touches.length !== 1) return;
					self._drawerTouchStartX = e.touches[0].clientX;
				},
				{ passive: true }
			);
			panel.addEventListener(
				'touchend',
				(e) => {
					if (!panel.classList.contains('is-open')) return;
					const t = e.changedTouches[0];
					if (!t) return;
					if (t.clientX - self._drawerTouchStartX < -80) close();
				},
				{ passive: true }
			);

			const tabSelector =
				'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
			panel.addEventListener('keydown', (e) => {
				if (e.key !== 'Tab' || !panel.classList.contains('is-open')) return;
				const items = Array.from(panel.querySelectorAll(tabSelector)).filter((el) => {
					const h = /** @type {HTMLElement} */ (el);
					return h.offsetParent !== null || h.offsetWidth > 0 || h.offsetHeight > 0;
				});
				if (items.length === 0) return;
				const first = /** @type {HTMLElement} */ (items[0]);
				const last = /** @type {HTMLElement} */ (items[items.length - 1]);
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			});

			if (!filtronDrawerEscBound) {
				filtronDrawerEscBound = true;
				document.addEventListener('keydown', (e) => {
					if (e.key !== 'Escape') return;
					const p = document.querySelector('.filtron-offcanvas.is-open');
					if (!p) return;
					const r = p.closest('[data-filtron-group]');
					if (r) ensureCore().closeMobileDrawer(/** @type {HTMLElement} */ (r));
				});
			}
		}

		/**
		 * @param {Record<string, unknown>} row
		 * @returns {{ min: number, max: number }|null}
		 */
		getRangeBoundsFromDom(row) {
			const key = String(row.key || '');
			if (!key) return null;
			for (const root of this._roots) {
				const widgets = root.querySelectorAll('[data-filtron-type="range"][data-filtron-key]');
				for (const el of widgets) {
					if (String(el.getAttribute('data-filtron-key') || '') !== key) continue;
					const min = parseFloat(el.getAttribute('data-filtron-min') || '0', 10);
					const max = parseFloat(el.getAttribute('data-filtron-max') || '0', 10);
					if (Number.isFinite(min) && Number.isFinite(max)) {
						return { min, max };
					}
				}
			}
			return null;
		}

		/**
		 * @param {Record<string, unknown>} row
		 * @returns {boolean}
		 */
		isRangeRowActive(row) {
			const min = Number(row.min);
			const max = Number(row.max);
			if (!Number.isFinite(min) || !Number.isFinite(max)) return false;

			let dmin = Number(row.defaultMin);
			let dmax = Number(row.defaultMax);
			if (!Number.isFinite(dmin) || !Number.isFinite(dmax)) {
				const bounds = this.getRangeBoundsFromDom(row);
				if (bounds) {
					dmin = bounds.min;
					dmax = bounds.max;
				}
			}
			if (!Number.isFinite(dmin) || !Number.isFinite(dmax)) return true;
			return Math.abs(min - dmin) > RANGE_EPSILON || Math.abs(max - dmax) > RANGE_EPSILON;
		}

		/**
		 * @param {number} resultCount
		 * @param {number} activeFilters
		 * @param {number} executionMs
		 */
		updateSummary(resultCount, activeFilters, executionMs) {
			this._roots.forEach((root) => {
				const countEl = root.querySelector('.filtron-summary__value--count');
				const timeEl = root.querySelector('.filtron-summary__value--time');
				const activeEl = root.querySelector('.filtron-summary__value--active');
				const metaCountEl = root.querySelector('.filtron-summary__result-count');
				if (countEl) countEl.textContent = String(resultCount);
				if (timeEl) {
					const ms = Math.max(0, Math.round(executionMs));
					timeEl.textContent = `${ms}ms`;
					timeEl.setAttribute('title', `${ms}ms`);
				}
				if (activeEl) activeEl.textContent = String(activeFilters);
				if (metaCountEl) metaCountEl.textContent = String(resultCount);
			});
			this._roots.forEach((r) => this.updateDrawerUi(r));
		}

		/**
		 * @param {Record<string, unknown>|null} data JSON success data or null to hide.
		 */
		updateDebugPanel(data) {
			const fv = global.filtronVars;
			const show = fv && fv.debug && fv.debug.show;
			this._roots.forEach((root) => {
				let el = root.querySelector('[data-filtron-debug-panel]');
				if (!show || !data || !data.debug) {
					if (el) {
						el.hidden = true;
						el.textContent = '';
					}
					return;
				}
				if (!el) {
					el = document.createElement('p');
					el.className = 'filtron-debug-panel';
					el.setAttribute('data-filtron-debug-panel', '1');
					el.setAttribute('aria-live', 'polite');
					const meta = root.querySelector('.filtron-widget__result-meta');
					if (meta && meta.parentNode) meta.parentNode.insertBefore(el, meta.nextSibling);
					else root.appendChild(el);
				}
				el.hidden = false;
				const d = /** @type {Record<string, unknown>} */ (data.debug);
				const i18n = (fv && fv.i18n) || {};
				const hit = !!d.cache_hit;
				const hitLabel = hit ? String(i18n.debugCacheHit || 'Cache hit') : String(i18n.debugFresh || 'Fresh query');
				const qn = d.wp_query_count != null ? Number(d.wp_query_count) : 0;
				const srv = d.server_time_ms != null ? Number(d.server_time_ms) : 0;
				const ajaxMs = d.execution_time_ms != null ? Number(d.execution_time_ms) : 0;
				const qTpl = String(i18n.debugQueries || '%s SQL queries');
				const sTpl = String(i18n.debugServerMs || '%s ms server');
				const aTpl = String(i18n.debugAjaxMs || '%s ms AJAX');
				const parts = [
					hitLabel,
					sTpl.replace('%s', String(srv)),
					qTpl.replace('%s', String(qn)),
					aTpl.replace('%s', String(ajaxMs)),
				];
				el.textContent = parts.join(' · ');
			});
		}

		/**
		 * @param {{ hasMore: boolean }} meta
		 */
		updateLoadMoreState(meta) {
			this._roots.forEach((root) => {
				const btn = root.querySelector('[data-filtron-load-more="1"]');
				if (!btn) return;
				const i18n = (global.filtronVars && global.filtronVars.i18n) || {};
				if (root.querySelector('[data-filtron-pagination="1"]')) {
					btn.hidden = true;
					btn.disabled = true;
					btn.classList.remove('filtron-load-more--end');
					btn.removeAttribute('title');
					return;
				}
				const hasMore = !!(meta && meta.hasMore);
				const disabled = this.state.loading || !hasMore;
				btn.disabled = disabled;
				btn.hidden = !hasMore && !this.state.loading;
				btn.classList.toggle('filtron-load-more--end', disabled && !this.state.loading && !hasMore);
				if (disabled && !this.state.loading && !hasMore) {
					btn.setAttribute('title', String(i18n.noMorePages || i18n.noMoreResults || 'No more results'));
				} else if (this.state.loading) {
					btn.setAttribute('title', String(i18n.loading || 'Loading...'));
				} else {
					btn.removeAttribute('title');
				}
			});
		}

		/**
		 * @param {{ hasMore: boolean, currentPage: number, totalPages: number }} meta
		 */
		updatePaginationState(meta) {
			this._roots.forEach((root) => {
				const holder = root.querySelector('[data-filtron-pagination="1"]');
				if (!holder) return;
				const prev = holder.querySelector('[data-filtron-page-prev]');
				const next = holder.querySelector('[data-filtron-page-next]');
				const label = holder.querySelector('[data-filtron-page-label]');
				const i18n = (global.filtronVars && global.filtronVars.i18n) || {};
				const hasMore = !!(meta && meta.hasMore);
				const currentPage = Math.max(1, Number(meta && meta.currentPage ? meta.currentPage : this.state.page) || 1);
				const totalPages = Math.max(1, Number(meta && meta.totalPages ? meta.totalPages : currentPage) || 1);
				if (label) {
					label.textContent = `Page ${String(currentPage)} of ${String(totalPages)}`;
					const tpl = String(i18n.paginationStatus || '');
					if (tpl) {
						label.setAttribute(
							'aria-label',
							tpl.replace('%1$s', String(currentPage)).replace('%2$s', String(totalPages))
						);
					} else {
						label.setAttribute('aria-label', label.textContent);
					}
				}
				if (prev) {
					const atStart = currentPage <= 1;
					prev.disabled = atStart || this.state.loading;
					prev.classList.toggle('filtron-pagination__btn--edge', atStart && !this.state.loading);
					if (atStart && !this.state.loading) prev.setAttribute('title', String(i18n.firstPage || 'Already on first page'));
					else if (this.state.loading) prev.setAttribute('title', String(i18n.loading || 'Loading...'));
					else prev.removeAttribute('title');
				}
				if (next) {
					const atEnd = !hasMore;
					next.disabled = atEnd || this.state.loading;
					next.classList.toggle('filtron-pagination__btn--edge', atEnd && !this.state.loading);
					if (atEnd && !this.state.loading) next.setAttribute('title', String(i18n.noMorePages || 'No more pages'));
					else if (this.state.loading) next.setAttribute('title', String(i18n.loading || 'Loading...'));
					else next.removeAttribute('title');
				}
			});
		}

		/**
		 * @returns {Record<string, unknown>[]}
		 */
		collectAllRows() {
			const map = {};
			this._roots.forEach((r) => {
				this.collectRows(r).forEach((row) => {
					map[rowId(row)] = row;
				});
			});
			return Object.values(map);
		}

		/**
		 * @param {HTMLElement} root
		 * @returns {Record<string, unknown>[]}
		 */
		collectRows(root) {
			const out = [];
			const widgets = root.querySelectorAll('[data-filtron-type][data-filtron-key]');
			widgets.forEach((el) => {
				const type = (el.getAttribute('data-filtron-type') || '').toLowerCase();
				const custom = typeHandlers.get(type);
				if (custom && typeof custom.collect === 'function') {
					const row = custom.collect(el);
					if (row) out.push(row);
					return;
				}
				if (type === 'checkbox') {
					const key = el.getAttribute('data-filtron-key') || '';
					const logic = (el.getAttribute('data-filtron-logic') || 'OR').toUpperCase();
					const values = [];
					el.querySelectorAll('input.filtron-filter-checkbox__input[type="checkbox"]:checked').forEach((inp) => {
						values.push(String(inp.value || ''));
					});
					if (key && values.length)
						out.push({ type: 'checkbox', key, values, logic: logic === 'AND' ? 'AND' : 'OR' });
				} else if (type === 'select') {
					const key = el.getAttribute('data-filtron-key') || '';
					const select = el.querySelector('.filtron-filter-select__input');
					const value = select ? String(select.value || '') : '';
					if (key && value) out.push({ type: 'select', key, values: [value], logic: 'OR' });
				} else if (type === 'range') {
					const key = el.getAttribute('data-filtron-key') || '';
					const slug = el.getAttribute('data-filtron-url-slug') || '';
					const dmin = parseFloat(el.getAttribute('data-filtron-min') || '0', 10);
					const dmax = parseFloat(el.getAttribute('data-filtron-max') || '0', 10);
					const cmin = parseFloat(el.getAttribute('data-filtron-current-min') || '0', 10);
					const cmax = parseFloat(el.getAttribute('data-filtron-current-max') || '0', 10);
					const active =
						Number.isFinite(dmin) &&
						Number.isFinite(dmax) &&
						(Math.abs(cmin - dmin) > RANGE_EPSILON || Math.abs(cmax - dmax) > RANGE_EPSILON);
					if (key && active) {
						out.push({ type: 'range', key, min: cmin, max: cmax, urlSlug: slug, defaultMin: dmin, defaultMax: dmax });
					}
				} else if (type === 'search') {
					const key = el.getAttribute('data-filtron-key') || '';
					const input = el.querySelector('.filtron-filter-search__input');
					const val = input ? String(input.value || '').trim() : '';
					const urlParam = el.getAttribute('data-filtron-url-param') || '';
					if (key && val.length >= 2) {
						const o = { type: 'search', key, value: val };
						if (urlParam) o.urlParam = urlParam;
						out.push(o);
					}
				}
			});
			return out;
		}

		async refreshResults() {
			const fv = global.filtronVars;
			if (!fv || !fv.ajax_url || !fv.nonce) return;

			if (this._abort) this._abort.abort();
			this._abort = new AbortController();

			this.state.loading = true;

			const grids = [];
			this._roots.forEach((r) => {
				const sel = r.getAttribute('data-filtron-grid') || '#filtron-results';
				const g = document.querySelector(sel);
				if (g) grids.push(g);
			});

			if (!this._isAppending) {
				const skeletonCount = Math.max(1, Number(this._cfg.perPage || 6));
				grids.forEach((g) => {
					g.classList.add('filtron-skeleton');
					g.innerHTML = renderSkeletonCards(skeletonCount);
				});
			}

			let rows = Object.values(this.state.filters);
			const normalizedRows = rows.filter((row) => !(row && typeof row === 'object' && String(row.type || '') === 'range' && !this.isRangeRowActive(row)));
			if (normalizedRows.length !== rows.length) {
				this.state.filters = {};
				normalizedRows.forEach((r) => {
					this.state.filters[rowId(r)] = r;
				});
				rows = normalizedRows;
				this._roots.forEach((r) => this.applyDomFromState(r));
				if (this._roots[0]) this.renderChips(this._roots[0]);
				this.syncUrl({ replace: true });
			}
			const activeSelections = this.countActiveSelections(rows);
			const requestedPage = Math.max(1, Number(this.state.page) || 1);
			this.updateLoadMoreState({ hasMore: true });
			this.updatePaginationState({ hasMore: true, currentPage: requestedPage, totalPages: Math.max(1, requestedPage) });
			const body = new URLSearchParams();
			body.set('action', 'filtron_filter');
			body.set('nonce', fv.nonce);
			body.set('filters', JSON.stringify(rows));

			const c = this._cfg;
			if (c.perPage) body.set('per_page', String(c.perPage));
			if (this.state.page > 1) body.set('page', String(this.state.page));
			if (c.orderby) body.set('orderby', String(c.orderby));
			if (c.order) body.set('order', String(c.order));
			if (c.postType) body.set('post_type', String(c.postType));

			try {
				const res = await fetch(fv.ajax_url, {
					method: 'POST',
					credentials: 'same-origin',
					signal: this._abort.signal,
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString(),
				});

				if (!res.ok) {
					const httpErr = new Error(`HTTP ${res.status}`);
					httpErr.status = res.status;
					throw httpErr;
				}

				const json = await res.json();
				if (!json || json.success !== true) {
					const msg = json && json.data && json.data.message ? json.data.message : 'Server error';
					const appErr = new Error(msg);
					appErr.status = json && json.data && json.data.status ? Number(json.data.status) : 500;
					throw appErr;
				}

				const data = json.data || {};
				const fc = data.filter_counts || data.filters || {};
				const count = Number(data.total_count || data.count || 0);
				const executionMs = Number(data.execution_time_ms || 0);
				const currentPage = Math.max(1, Number(data.current_page || this.state.page) || 1);
				const totalPages = Math.max(1, Number(data.total_pages || currentPage) || 1);
				const hasMore = Boolean(data.has_more || currentPage < totalPages);
				const canAppend = this._isAppending && currentPage === requestedPage;
				this.state.page = currentPage;
				if (requestedPage !== currentPage) {
					this.syncUrl({ replace: true });
				}
				this.applyFacetCounts(fc);
				this.updateSummary(count, activeSelections, executionMs);
				this.state.loading = false;
				this.updateLoadMoreState({ hasMore });
				this.updatePaginationState({ hasMore, currentPage, totalPages });
				this.updateDebugPanel(data);

				const posts = data.posts || [];
				const primaryRoot = this._roots[0] || null;
				grids.forEach((grid) => {
					grid.classList.remove('filtron-skeleton');
					if (canAppend) {
						appendRenderedPosts(grid, posts);
					} else {
						grid.innerHTML = defaultRenderPosts(posts, primaryRoot);
					}
				});
			} catch (e) {
				if (e && e.name === 'AbortError') return;
				this.updateDebugPanel(null);
				grids.forEach((g) => g.classList.remove('filtron-skeleton'));
				this.state.loading = false;
				this.updateSummary(0, activeSelections, 0);
				this.updateLoadMoreState({ hasMore: false });
				this.updatePaginationState({ hasMore: false, currentPage: this.state.page, totalPages: this.state.page });
				const net = e && (e.name === 'TypeError' || !('status' in /** @type {any} */ (e)));
				const i18n = (global.filtronVars && global.filtronVars.i18n) || {};
				const msg = net
					? String(i18n.networkError || 'Network issue. Please check your connection.')
					: String(i18n.serverError || 'Unable to load results right now.');
				const retryText = String(i18n.retry || 'Retry');
				grids.forEach((grid) => {
					grid.innerHTML = renderErrorState(msg, retryText);
					const retryBtn = grid.querySelector('[data-filtron-retry="1"]');
					if (retryBtn) {
						retryBtn.addEventListener('click', (ev) => {
							ev.preventDefault();
							this.refreshResults();
						});
					}
				});
				const cfg = this._cfg;
				if (cfg && cfg.onError) cfg.onError(net ? 'network' : 'server', e);
			} finally {
				this.state.loading = false;
				this._isAppending = false;
			}
		}

		/**
		 * @param {Record<string, number>} fc
		 */
		applyFacetCounts(fc) {
			for (const [pairKey, count] of Object.entries(fc)) {
				const { key, value } = splitFacetKey(pairKey);
				const esc = (s) => (typeof global.CSS !== 'undefined' && global.CSS.escape ? global.CSS.escape(s) : s.replace(/"/g, '\\"'));
				const inp = document.querySelector(
					`input.filtron-filter-checkbox__input[data-filtron-key="${esc(key)}"][data-filtron-value="${esc(value)}"]`
				);
				if (inp) {
					const item = inp.closest('.filtron-filter-checkbox__item');
					const cntEl = item && item.querySelector('.filtron-filter-checkbox__count');
					if (cntEl) cntEl.textContent = '(' + String(count) + ')';
					if (item) item.classList.toggle('filtron-option--dim', count === 0);
				}

				const opt = document.querySelector(
					`select.filtron-filter-select__input[data-filtron-key="${esc(key)}"] option[value="${esc(value)}"]`
				);
				if (opt) {
					opt.disabled = count === 0;
				}
			}
		}
	}

	/**
	 * @param {Record<string, unknown>[]} rows
	 * @param {HTMLElement} root
	 */
	function applyRowsToDom(root, rows) {
		const byId = new Map(rows.map((r) => [rowId(r), r]));
		root.querySelectorAll('[data-filtron-type][data-filtron-key]').forEach((el) => {
			const type = (el.getAttribute('data-filtron-type') || '').toLowerCase();
			const key = el.getAttribute('data-filtron-key') || '';
			const id = `${type}:${key}`;
			const row = byId.get(id);
			const custom = typeHandlers.get(type);
			if (custom && typeof custom.applyFromState === 'function') {
				custom.applyFromState(el, row || null);
				return;
			}
			if (type === 'checkbox' && row && row.values) {
				const set = new Set(row.values.map((v) => String(v)));
				el.querySelectorAll('input.filtron-filter-checkbox__input[type="checkbox"]').forEach((inp) => {
					inp.checked = set.has(String(inp.value));
				});
			} else if (type === 'checkbox') {
				el.querySelectorAll('input.filtron-filter-checkbox__input[type="checkbox"]').forEach((inp) => {
					inp.checked = false;
				});
			} else if (type === 'select') {
				const select = el.querySelector('.filtron-filter-select__input');
				if (select) select.value = row && row.values && row.values[0] != null ? String(row.values[0]) : '';
			} else if (type === 'range' && row && row.min != null && row.max != null) {
				const track = el.querySelector('.filtron-filter-range__track');
				if (track && track.noUiSlider) {
					track.noUiSlider.set([Number(row.min), Number(row.max)]);
				}
				const fMin = el.querySelector('.filtron-filter-range__fallback-min');
				const fMax = el.querySelector('.filtron-filter-range__fallback-max');
				if (fMin) fMin.value = String(row.min);
				if (fMax) fMax.value = String(row.max);
				el.setAttribute('data-filtron-current-min', String(row.min));
				el.setAttribute('data-filtron-current-max', String(row.max));
				const rmin = el.querySelector('.filtron-filter-range__readout-min');
				const rmax = el.querySelector('.filtron-filter-range__readout-max');
				if (rmin) rmin.textContent = String(row.min);
				if (rmax) rmax.textContent = String(row.max);
			} else if (type === 'search') {
				const inp = el.querySelector('.filtron-filter-search__input');
				if (inp) inp.value = row && row.value != null ? String(row.value) : '';
			}
		});
	}

	/**
	 * @param {{ filters: Record<string, unknown>, page: number, loading: boolean }} state
	 * @param {string} href
	 * @param {{ orderby?: string, order?: string, view?: string }} [ui]
	 */
	function filterToURL(state, href, ui) {
		const u = new URL(href, global.location.origin);
		const next = new URL(u.origin + u.pathname);
		u.searchParams.forEach((val, k) => {
			if (!isFiltronParam(k)) next.searchParams.append(k, val);
		});
		const rows = Object.values(state.filters);
		for (const row of rows) {
			if (!row || typeof row !== 'object') continue;
			const t = String(row.type);
			if (t === 'checkbox' && Array.isArray(row.values)) {
				for (const v of row.values) {
					next.searchParams.append(`filtron[${String(row.key)}][]`, String(v));
				}
			} else if (t === 'select' && Array.isArray(row.values) && row.values[0]) {
				next.searchParams.set(`filtron_${String(row.key)}`, String(row.values[0]));
			} else if (t === 'range' && row.min != null && row.max != null) {
				const slug = row.urlSlug || 'range';
				next.searchParams.set(`filtron_${String(slug)}_min`, String(row.min));
				next.searchParams.set(`filtron_${String(slug)}_max`, String(row.max));
			} else if (t === 'search' && row.value) {
				const param = row.urlParam || `filtron_${String(row.key).replace(/_/g, '-')}_s`;
				next.searchParams.set(param, String(row.value));
			}
		}
		if (state.page > 1) next.searchParams.set('filtron_page', String(state.page));
		if (ui && ui.orderby) next.searchParams.set('filtron_orderby', String(ui.orderby));
		if (ui && ui.order) next.searchParams.set('filtron_order', String(ui.order).toUpperCase());
		if (ui && ui.view && ui.view !== 'grid') next.searchParams.set('filtron_view', String(ui.view));
		return next.toString();
	}

	/**
	 * @param {string} k
	 */
	function isFiltronParam(k) {
		return k === 'filtron' || k.indexOf('filtron_') === 0 || k.indexOf('filtron[') === 0;
	}

	/**
	 * @param {string} href
	 * @returns {Record<string, unknown>}
	 */
	function urlToFilters(href) {
		const u = new URL(href, global.location.origin);
		const out = {};
		const params = u.searchParams;
		const reserved = new Set(['filtron_page', 'filtron_orderby', 'filtron_order', 'filtron_view']);

		const filtronNested = {};
		params.forEach((val, key) => {
			const m = key.match(/^filtron\[(.+)\]$/);
			if (m) {
				const inner = m[1];
				const am = inner.match(/^(.+)\[\]$/);
				if (am) {
					const fk = am[1];
					if (!filtronNested[fk]) filtronNested[fk] = [];
					filtronNested[fk].push(val);
				}
			}
		});

		Object.keys(filtronNested).forEach((fk) => {
			const values = filtronNested[fk];
			if (values && values.length) {
				out[rowId({ type: 'checkbox', key: fk })] = {
					type: 'checkbox',
					key: fk,
					values,
					logic: 'OR',
				};
			}
		});

		const seenRange = new Set();
		params.forEach((val, key) => {
			const smin = key.match(/^filtron_(.+)_min$/);
			if (smin) {
				const slug = smin[1];
				if (seenRange.has(slug)) return;
				const maxK = `filtron_${slug}_max`;
				if (params.has(maxK)) {
					seenRange.add(slug);
					const kGuess = slug.replace(/-/g, '_');
					out[rowId({ type: 'range', key: kGuess })] = {
						type: 'range',
						key: kGuess,
						min: parseFloat(params.get(`filtron_${slug}_min`) || '0', 10),
						max: parseFloat(params.get(`filtron_${slug}_max`) || '0', 10),
						urlSlug: slug,
					};
				}
			}
		});

		params.forEach((val, key) => {
			if (key.endsWith('_s') && key.indexOf('filtron_') === 0 && val && val.length >= 2) {
				const rest = key.replace(/^filtron_/, '').replace(/_s$/, '');
				const searchKey = rest.replace(/-/g, '_');
				out[rowId({ type: 'search', key: searchKey })] = {
					type: 'search',
					key: searchKey,
					value: val,
					urlParam: key,
				};
			}
		});

		params.forEach((val, key) => {
			if (/^filtron_.+_(min|max)$/.test(key)) return;
			if (/^filtron_.+_s$/.test(key)) return;
			if (key.indexOf('filtron[') === 0) return;
			if (reserved.has(key)) return;
			if (key.indexOf('filtron_') === 0) {
				const fk = key.replace(/^filtron_/, '');
				if (fk && val) {
					const vals = val.split(',').map((s) => s.trim()).filter(Boolean);
					if (vals.length) {
						out[rowId({ type: 'select', key: fk })] = {
							type: 'select',
							key: fk,
							values: vals,
							logic: 'OR',
						};
					}
				}
			}
		});

		return out;
	}

	/**
	 * @param {string} href
	 * @returns {{ filters: Record<string, unknown>, page: number, orderby: string, order: string, view: string }}
	 */
	function urlToState(href) {
		const u = new URL(href, global.location.origin);
		const orderRaw = String(u.searchParams.get('filtron_order') || 'DESC').toUpperCase();
		const viewRaw = String(u.searchParams.get('filtron_view') || 'grid').toLowerCase();
		return {
			filters: urlToFilters(href),
			page: parseInt(u.searchParams.get('filtron_page') || '1', 10) || 1,
			orderby: String(u.searchParams.get('filtron_orderby') || ''),
			order: orderRaw === 'ASC' ? 'ASC' : 'DESC',
			view: viewRaw === 'list' ? 'list' : 'grid',
		};
	}

	/**
	 * @param {any[]} posts
	 * @param {HTMLElement|null} rootEl
	 */
	function defaultRenderPosts(posts, rootEl) {
		if (!posts.length) {
			const i18n = (global.filtronVars && global.filtronVars.i18n) || {};
			const msg =
				(global.filtronVars && global.filtronVars.i18n && global.filtronVars.i18n.noResults) || 'No results found.';
			const clearLbl = escHtml(String(i18n.clearFilters || i18n.clearAll || 'Clear all filters'));
			const resetLbl = escHtml(String(i18n.resetRanges || 'Reset price and range sliders'));
			const hasRange = !!(rootEl && rootEl.querySelector('[data-filtron-type="range"]'));
			const resetHidden = hasRange ? '' : ' hidden';
			return `<div class="filtron-results__empty" role="status">
				<div class="filtron-results__empty-icon" aria-hidden="true">🔎</div>
				<p class="filtron-results__empty-text">${escHtml(msg)}</p>
				<div class="filtron-results__empty-actions">
					<button type="button" class="filtron-results__empty-btn" data-filtron-empty-clear="1">${clearLbl}</button>
					<button type="button" class="filtron-results__empty-btn filtron-results__empty-btn--secondary"${resetHidden} data-filtron-empty-reset-range="1">${resetLbl}</button>
				</div>
			</div>`;
		}
		return posts
			.map((p) => {
				const title = escHtml(String(p.post_title || ''));
				const permalink = escHtml(String(p.permalink || '#'));
				const thumb = String(p.thumbnail || '').trim();
				const thumbHtml = thumb
					? `<img class="filtron-result-card__image" src="${escHtml(thumb)}" alt="${title}" loading="lazy" />`
					: `<div class="filtron-result-card__image filtron-result-card__image--placeholder" aria-hidden="true">🛍</div>`;
				const excerpt = escHtml(String(p.excerpt || ''));
				const price = escHtml(decodeHtmlEntities(String(p.price || '')));
				const brand = escHtml(String(p.brand || ''));
				const rating = Number(p.rating || 0);
				const priceHtml = price ? `<div class="filtron-result-card__price">${price}</div>` : '';
				const brandHtml = brand ? `<div class="filtron-result-card__brand">${brand}</div>` : '';
				const ratingHtml =
					rating > 0
						? `<div class="filtron-result-card__rating" aria-label="Rating ${rating.toFixed(1)} out of 5">★ ${rating.toFixed(1)}</div>`
						: '';
				return `<article class="filtron-result-card" data-id="${escHtml(String(p.ID))}">
					<a class="filtron-result-card__thumb" href="${permalink}">${thumbHtml}</a>
					<div class="filtron-result-card__body">
						<h3 class="filtron-result-card__title"><a href="${permalink}">${title}</a></h3>
						${brandHtml}
						${ratingHtml}
						${priceHtml}
						${excerpt ? `<p class="filtron-result-card__excerpt">${excerpt}</p>` : ''}
					</div>
				</article>`;
			})
			.join('');
	}

	/**
	 * @param {number} count
	 * @returns {string}
	 */
	function renderSkeletonCards(count) {
		const n = Math.max(1, Number(count) || 1);
		return new Array(n)
			.fill(0)
			.map(
				() => `<article class="filtron-result-card filtron-result-card--skeleton" aria-hidden="true">
					<div class="filtron-result-card__thumb">
						<div class="filtron-skel filtron-skel--image"></div>
					</div>
					<div class="filtron-result-card__body">
						<div class="filtron-skel filtron-skel--line filtron-skel--line-lg"></div>
						<div class="filtron-skel filtron-skel--line"></div>
						<div class="filtron-skel filtron-skel--line filtron-skel--line-sm"></div>
					</div>
				</article>`
			)
			.join('');
	}

	/**
	 * @param {Element} grid
	 * @param {any[]} posts
	 */
	function appendRenderedPosts(grid, posts) {
		if (!posts || !posts.length) return;
		const html = defaultRenderPosts(posts, null);
		grid.insertAdjacentHTML('beforeend', html);
	}

	/**
	 * @param {string} message
	 * @param {string} retryText
	 * @returns {string}
	 */
	function renderErrorState(message, retryText) {
		return `<div class="filtron-results__error" role="alert">
			<div class="filtron-results__error-icon" aria-hidden="true">\u26A0</div>
			<p class="filtron-results__error-text">${escHtml(message)}</p>
			<button type="button" class="filtron-results__retry" data-filtron-retry="1">${escHtml(retryText)}</button>
		</div>`;
	}

	let core = /** @type {FiltronCore|null} */ (null);

	function ensureCore() {
		if (!core) core = new FiltronCore();
		return core;
	}

	global.Filtron = {
		state: { filters: {}, page: 1, loading: false },

		/**
		 * @param {{ root?: HTMLElement|string, group?: HTMLElement|string, grid?: string, chips?: string, clear?: string, perPage?: number, orderby?: string, order?: string, postType?: string, view?: string, onError?: (kind: 'network'|'server', err: unknown) => void }} config
		 */
		init(config) {
			const raw = config.root || config.group || document.querySelector('[data-filtron-group]');
			const root =
				typeof raw === 'string' ? /** @type {HTMLElement|null} */ (document.querySelector(raw)) : raw;
			if (!root) return null;
			const parseNum = (v) => {
				const n = parseInt(String(v || ''), 10);
				return Number.isFinite(n) && n > 0 ? n : undefined;
			};
			const perPage = config.perPage || parseNum(root.getAttribute('data-filtron-per-page'));
			const orderby = config.orderby || root.getAttribute('data-filtron-orderby') || '';
			const order = config.order || root.getAttribute('data-filtron-order') || '';
			const postType = config.postType || root.getAttribute('data-filtron-post-type') || '';
			const view = config.view || root.getAttribute('data-filtron-view') || 'grid';
			const c = ensureCore();
			c.registerGroup({
				root,
				grid: config.grid || '',
				chips: config.chips,
				clear: config.clear,
				perPage,
				orderby,
				order,
				postType,
				view,
				onError: config.onError,
			});
			return c;
		},

		/**
		 * @param {string} type
		 * @param {{ collect?: (el: Element) => unknown|null, applyFromState?: (el: Element, row: unknown|null) => void }} handler
		 */
		registerFilterType(type, handler) {
			if (!type || !handler) return;
			typeHandlers.set(type.toLowerCase(), handler);
		},

		filterToURL: (state, href, ui) => filterToURL(state, href || global.location.href, ui || {}),
		/** @deprecated Use urlToFilter */
		URLToFilter: (href) => urlToFilters(href || global.location.href),
		urlToFilter: (href) => urlToFilters(href || global.location.href),
	};

	global.Filtron.registerFilterType('swatch', {
		collect: () => null,
		applyFromState: () => {},
	});

	global.addEventListener('popstate', () => {
		if (!core) return;
		const parsed = urlToState(global.location.href);
		core.state.filters = parsed.filters;
		core.state.page = parsed.page;
		core._cfg.orderby = parsed.orderby || core._cfg.orderby || '';
		core._cfg.order = parsed.order || core._cfg.order || 'DESC';
		core._cfg.view = parsed.view || core._cfg.view || 'grid';
		core._roots.forEach((r) => {
			core.applyToolbarFromCfg(r);
			core.applyDomFromState(r);
			if (core._roots[0]) core.renderChips(core._roots[0]);
		});
		core.refreshResults();
	});

	function autoBoot() {
		document.querySelectorAll('[data-filtron-group]').forEach((el) => {
			global.Filtron.init({ root: /** @type {HTMLElement} */ (el) });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', autoBoot);
	} else {
		autoBoot();
	}
})(typeof window !== 'undefined' ? window : globalThis);
