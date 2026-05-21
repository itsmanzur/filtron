/**
 * Filtron admin — Sortable filter list, live preview, AJAX save/reorder.
 *
 * @package Filtron
 */
(function (global) {
	'use strict';

	/** @type {typeof Sortable|undefined} */
	const SortableLib = global.Sortable;

	/**
	 * @returns {FiltronAdminConfig}
	 */
	function cfg() {
		return global.filtronAdmin || {};
	}

	/**
	 * @param {string} msg
	 * @param {boolean} isError
	 */
	function toast(msg, isError) {
		const el = document.createElement('div');
		el.className = 'filtron-admin-notice' + (isError ? ' filtron-admin-notice--error' : '');
		el.setAttribute('role', 'status');
		el.textContent = msg;
		document.body.appendChild(el);
		setTimeout(() => {
			el.remove();
		}, 3200);
	}

	/**
	 * @param {string} action
	 * @param {Record<string, string>} fields
	 */
	async function postAjax(action, fields) {
		const c = cfg();
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', c.nonce || '');
		Object.keys(fields).forEach((k) => body.set(k, fields[k]));

		const res = await fetch(c.ajax_url || '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		});

		if (!res.ok) {
			throw new Error('HTTP ' + res.status);
		}

		const json = await res.json();
		if (!json || json.success !== true) {
			const m = json && json.data && json.data.message ? json.data.message : (c.i18n && c.i18n.error) || 'Error';
			throw new Error(m);
		}
		return json.data || {};
	}

	/**
	 * @param {FiltronFilterItem} item
	 * @returns {Record<string, unknown>}
	 */
	function defaultConfigForType(item) {
		const t = item.filter_type;
		if (t === 'checkbox') {
			return { logic: 'OR', show_count: true };
		}
		if (t === 'select') {
			return { placeholder: 'Any', show_count: true };
		}
		if (t === 'range') {
			return { prefix: '', suffix: '', step: 1 };
		}
		return {};
	}

	/**
	 * @param {FiltronFilterItem} item
	 * @returns {string}
	 */
	function renderPreviewHtml(item) {
		const t = item.filter_type;
		const label = escapeHtml(item.label || '');
		const c = item.config || {};

		if (t === 'checkbox') {
			const logic = escapeHtml(String(c.logic || 'OR'));
			const sc = c.show_count !== false;
			return (
				'<div class="filtron-preview-mock filtron-preview-mock--checkbox">' +
				'<div class="filtron-preview-title">' +
				label +
				'</div>' +
				'<div class="filtron-preview-meta">Logic: ' +
				logic +
				' · Show count: ' +
				(sc ? 'On' : 'Off') +
				'</div>' +
				'<ul><li>…</li><li>…</li></ul>' +
				'</div>'
			);
		}

		if (t === 'range') {
			const pre = escapeHtml(String(c.prefix ?? ''));
			const suf = escapeHtml(String(c.suffix ?? ''));
			const step = escapeHtml(String(c.step ?? 1));
			return (
				'<div class="filtron-preview-mock filtron-preview-mock--range">' +
				'<div class="filtron-preview-title">' +
				label +
				'</div>' +
				'<div class="filtron-preview-meta">' +
				pre +
				' min — max ' +
				suf +
				' (step ' +
				step +
				')</div>' +
				'<div class="filtron-preview-track">[ ————●———— ]</div>' +
				'</div>'
			);
		}

		if (t === 'select') {
			const placeholder = escapeHtml(String(c.placeholder || 'Any'));
			const sc = c.show_count !== false;
			return (
				'<div class="filtron-preview-mock filtron-preview-mock--select">' +
				'<label class="filtron-preview-title">' +
				label +
				'</label>' +
				'<select class="widefat" disabled>' +
				'<option>' +
				placeholder +
				'</option>' +
				'<option>Blue' +
				(sc ? ' (12)' : '') +
				'</option>' +
				'<option>Red' +
				(sc ? ' (8)' : '') +
				'</option>' +
				'</select>' +
				'<div class="filtron-preview-meta">Show count: ' +
				(sc ? 'On' : 'Off') +
				'</div>' +
				'</div>'
			);
		}

		if (t === 'search') {
			return (
				'<div class="filtron-preview-mock filtron-preview-mock--search">' +
				'<label class="filtron-preview-title">' +
				label +
				'</label>' +
				'<input type="search" class="widefat" placeholder="…" disabled />' +
				'</div>'
			);
		}

		if (t === 'swatch') {
			const pro = cfg().isPro;
			const badge =
				!pro && cfg().i18n
					? '<span class="filtron-pro-badge">' + escapeHtml(cfg().i18n.proBadge || 'Pro') + '</span>'
					: '';
			return (
				'<div class="filtron-preview-mock filtron-preview-mock--swatch">' +
				'<div class="filtron-preview-title">' +
				label +
				badge +
				'</div>' +
				(!pro ? '<p class="description">' + escapeHtml(cfg().i18n.proDisabled || '') + '</p>' : '<div class="filtron-preview-swatches"><span></span><span></span><span></span></div>') +
				'</div>'
			);
		}

		return '<div class="filtron-preview-mock"><strong>' + label + '</strong></div>';
	}

	function escapeHtml(s) {
		const d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	class FiltronAdminApp {
		constructor() {
			this.root = document.getElementById('filtron-admin-root');
			this.listEl = document.getElementById('filtron-filter-list');
			this.emptyEl = document.getElementById('filtron-filter-list-empty');
			this.editorEl = document.getElementById('filtron-filter-editor');
			this.previewEl = document.getElementById('filtron-live-preview');
			this.form = document.getElementById('filtron-filter-form');
			this.btnAdd = document.getElementById('filtron-add-filter');
			this.btnSave = document.getElementById('filtron-save-filter');
			this.modal = document.getElementById('filtron-filter-modal');
			this.typeFields = document.getElementById('filtron-type-fields');
			/** @type {FiltronFilterItem[]} */
			const initial = cfg().items;
			this.items = Array.isArray(initial) ? initial.slice() : [];
			/** @type {FiltronFilterItem|null} */
			this.selected = null;
			/** @type {number|null} */
			this.pendingDeleteId = null;
			/** @type {Sortable|null} */
			this.sortable = null;
			this.groupId = String((cfg().groupId != null ? cfg().groupId : this.root && this.root.dataset.groupId) || '0');
		}

		start() {
			if (!this.root || !this.listEl) return;

			this.renderList();
			this.bind();
			this.updateEmptyState();
			if (this.btnSave) this.btnSave.hidden = false;
		}

		updateEmptyState() {
			if (!this.emptyEl) return;
			this.emptyEl.hidden = this.items.length > 0;
		}

		renderList() {
			this.listEl.innerHTML = '';
			this.items.forEach((item) => {
				const active = item.is_active !== 0;
				const i18n = cfg().i18n || {};
				const pendingDelete = this.pendingDeleteId === item.id;
				const li = document.createElement('li');
				li.className = 'filtron-filter-item';
				if (pendingDelete) {
					li.classList.add('is-delete-pending');
				}
				if (!active) {
					li.classList.add('is-inactive');
				}
				li.dataset.itemId = String(item.id);
				if (this.selected && this.selected.id === item.id) {
					li.classList.add('is-active');
				}
				li.innerHTML =
					'<span class="filtron-drag-handle" title="Drag" aria-hidden="true">⋮⋮</span>' +
					'<span class="filtron-filter-item__main"><span class="filtron-filter-item__label">' +
					escapeHtml(item.label) +
					'</span>' +
					'<span class="filtron-filter-item__type">' +
					escapeHtml(item.filter_type) +
					'</span>' +
					'<span class="filtron-filter-item__status">' +
					(active ? 'Active' : 'Inactive') +
					'</span></span>' +
					'<span class="filtron-filter-item__actions">' +
					'<button type="button" class="button-link filtron-filter-item__action" data-filtron-toggle-active="1">' +
					escapeHtml(active ? i18n.deactivate || 'Deactivate' : i18n.activate || 'Activate') +
					'</button>' +
					'<button type="button" class="button-link button-link-delete filtron-filter-item__action" data-filtron-delete-filter="1">' +
					escapeHtml(pendingDelete ? i18n.confirmDelete || 'Confirm delete' : i18n.delete || 'Delete') +
					'</button>' +
					'</span>';
				li.addEventListener('click', (e) => {
					if ((e.target && /** @type {HTMLElement} */ (e.target).closest('.filtron-drag-handle, .filtron-filter-item__action'))) return;
					this.selectItem(item.id);
				});
				const toggle = li.querySelector('[data-filtron-toggle-active]');
				if (toggle) {
					toggle.addEventListener('click', (e) => {
						e.preventDefault();
						this.toggleItemActive(item.id);
					});
				}
				const del = li.querySelector('[data-filtron-delete-filter]');
				if (del) {
					del.addEventListener('click', (e) => {
						e.preventDefault();
						this.deleteItem(item.id);
					});
				}
				this.listEl.appendChild(li);
			});
			this.refreshSortable();
		}

		initSortable() {
			if (!SortableLib || !this.listEl) return;
			this.sortable = SortableLib.create(this.listEl, {
				handle: '.filtron-drag-handle',
				animation: 150,
				onEnd: () => this.onReorder(),
			});
		}

		refreshSortable() {
			if (this.sortable && typeof this.sortable.destroy === 'function') {
				this.sortable.destroy();
			}
			this.sortable = null;
			this.initSortable();
		}

		async onReorder() {
			const ids = Array.prototype.map.call(this.listEl.querySelectorAll('.filtron-filter-item'), (li) =>
				parseInt(li.dataset.itemId || '0', 10)
			).filter((id) => id > 0);

			const order = ids.map(String);
			const newItems = [];
			ids.forEach((id) => {
				const found = this.items.find((x) => x.id === id);
				if (found) newItems.push(found);
			});
			this.items = newItems;

			try {
				await postAjax('filtron_reorder_filters', {
					group_id: this.groupId,
					order: JSON.stringify(order),
				});
				const m = cfg().i18n && cfg().i18n.reordered;
				if (m) toast(m, false);
			} catch (e) {
				toast(e.message || 'Reorder failed', true);
				this.renderList();
			}
		}

		/**
		 * @param {number} id
		 */
		selectItem(id) {
			const item = this.items.find((x) => x.id === id);
			if (!item) return;
			if (!item.config) item.config = defaultConfigForType(item);
			this.selected = item;
			this.listEl.querySelectorAll('.filtron-filter-item').forEach((li) => {
				li.classList.toggle('is-active', parseInt(li.dataset.itemId || '0', 10) === id);
			});
			if (this.editorEl) this.editorEl.hidden = false;
			this.populateForm(item);
			this.renderTypeFields(item);
			this.updatePreview();
		}

		/**
		 * @param {FiltronFilterItem} item
		 */
		populateForm(item) {
			const idEl = document.getElementById('filtron-item-id');
			const labelEl = document.getElementById('filtron-field-label');
			const typeEl = document.getElementById('filtron-field-type');
			const stEl = document.getElementById('filtron-field-source-type');
			const skEl = document.getElementById('filtron-field-source-key');
			const activeEl = document.getElementById('filtron-field-active');
			if (idEl) idEl.value = String(item.id);
			if (labelEl) labelEl.value = item.label || '';
			if (typeEl) typeEl.value = item.filter_type || 'checkbox';
			if (stEl) stEl.value = item.source_type || 'taxonomy';
			if (skEl) skEl.value = item.source_key || '';
			if (activeEl) activeEl.checked = item.is_active !== 0;
		}

		/**
		 * @param {FiltronFilterItem} item
		 */
		renderTypeFields(item) {
			if (!this.typeFields) return;
			const t = item.filter_type;
			const c = item.config || {};

			if (t === 'checkbox') {
				const logic = c.logic === 'AND' ? 'AND' : 'OR';
				const show = c.show_count !== false;
				this.typeFields.innerHTML =
					'<fieldset><legend>Checkbox options</legend>' +
					'<p><label for="filtron-cfg-logic">Logic</label>' +
					'<select id="filtron-cfg-logic"><option value="OR">OR</option><option value="AND">AND</option></select></p>' +
					'<p><label><input type="checkbox" id="filtron-cfg-show-count" /> Show count</label></p>' +
					'</fieldset>';
				const logicEl = document.getElementById('filtron-cfg-logic');
				const showEl = document.getElementById('filtron-cfg-show-count');
				if (logicEl) logicEl.value = logic;
				if (showEl) showEl.checked = show;
			} else if (t === 'range') {
				this.typeFields.innerHTML =
					'<fieldset><legend>Range options</legend>' +
					'<p><label for="filtron-cfg-prefix">Prefix</label><input type="text" class="widefat" id="filtron-cfg-prefix" /></p>' +
					'<p><label for="filtron-cfg-suffix">Suffix</label><input type="text" class="widefat" id="filtron-cfg-suffix" /></p>' +
					'<p><label for="filtron-cfg-step">Step size</label><input type="number" class="small-text" id="filtron-cfg-step" step="any" min="0" /></p>' +
					'</fieldset>';
				const pre = document.getElementById('filtron-cfg-prefix');
				const suf = document.getElementById('filtron-cfg-suffix');
				const step = document.getElementById('filtron-cfg-step');
				if (pre) pre.value = String(c.prefix ?? '');
				if (suf) suf.value = String(c.suffix ?? '');
				if (step) step.value = String(c.step ?? 1);
			} else if (t === 'select') {
				const show = c.show_count !== false;
				this.typeFields.innerHTML =
					'<fieldset><legend>Select options</legend>' +
					'<p><label for="filtron-cfg-placeholder">Placeholder</label><input type="text" class="widefat" id="filtron-cfg-placeholder" placeholder="Any" /></p>' +
					'<p><label><input type="checkbox" id="filtron-cfg-show-count" /> Show count</label></p>' +
					'</fieldset>';
				const placeholderEl = document.getElementById('filtron-cfg-placeholder');
				const showEl = document.getElementById('filtron-cfg-show-count');
				if (placeholderEl) placeholderEl.value = String(c.placeholder ?? 'Any');
				if (showEl) showEl.checked = show;
			} else if (t === 'swatch') {
				const pro = cfg().isPro;
				const badge =
					'<span class="filtron-pro-badge">' + escapeHtml((cfg().i18n && cfg().i18n.proBadge) || 'Pro feature') + '</span>';
				this.typeFields.innerHTML = pro
					? '<p class="description">Swatch options ' + badge + '</p>'
					: '<p class="description">' + badge + ' ' + escapeHtml((cfg().i18n && cfg().i18n.proDisabled) || '') + '</p>';
			} else {
				this.typeFields.innerHTML = '';
			}

			this.typeFields.querySelectorAll('input, select').forEach((el) => {
				el.addEventListener('change', () => this.syncConfigFromTypeFields());
				el.addEventListener('input', () => this.syncConfigFromTypeFields());
			});
		}

		syncConfigFromTypeFields() {
			if (!this.selected) return;
			const t = this.selected.filter_type;
			const base = this.selected.config || defaultConfigForType(this.selected);

			if (t === 'checkbox') {
				const logicEl = document.getElementById('filtron-cfg-logic');
				const showEl = document.getElementById('filtron-cfg-show-count');
				base.logic = logicEl && logicEl.value === 'AND' ? 'AND' : 'OR';
				base.show_count = !!(showEl && showEl.checked);
			} else if (t === 'range') {
				const pre = document.getElementById('filtron-cfg-prefix');
				const suf = document.getElementById('filtron-cfg-suffix');
				const step = document.getElementById('filtron-cfg-step');
				base.prefix = pre ? pre.value : '';
				base.suffix = suf ? suf.value : '';
				base.step = step ? parseFloat(step.value, 10) || 1 : 1;
			} else if (t === 'select') {
				const placeholderEl = document.getElementById('filtron-cfg-placeholder');
				const showEl = document.getElementById('filtron-cfg-show-count');
				base.placeholder = placeholderEl ? placeholderEl.value.trim() : 'Any';
				base.show_count = !!(showEl && showEl.checked);
			}

			this.selected.config = base;
			this.updatePreview();
		}

		/**
		 * @param {FiltronFilterItem} item
		 */
		readFormIntoItem(item) {
			const labelEl = document.getElementById('filtron-field-label');
			const typeEl = document.getElementById('filtron-field-type');
			const stEl = document.getElementById('filtron-field-source-type');
			const skEl = document.getElementById('filtron-field-source-key');
			const activeEl = document.getElementById('filtron-field-active');
			const prevType = item.filter_type || 'checkbox';
			const nextType = typeEl ? typeEl.value : 'checkbox';
			item.label = labelEl ? labelEl.value.trim() : '';
			item.filter_type = nextType;
			item.source_type = stEl ? stEl.value : 'taxonomy';
			item.source_key = skEl ? skEl.value.trim() : '';
			item.is_active = activeEl && activeEl.checked ? 1 : 0;
			if (!item.config || prevType !== nextType) {
				item.config = defaultConfigForType(item);
				return;
			}
			this.syncConfigFromTypeFields();
		}

		updatePreview() {
			if (!this.previewEl || !this.selected) {
				if (this.previewEl) this.previewEl.innerHTML = '';
				return;
			}
			this.previewEl.innerHTML = renderPreviewHtml(this.selected);
		}

		bind() {
			if (this.btnAdd && this.modal) {
				this.btnAdd.addEventListener('click', () => {
					this.modal.showModal();
				});
			}

			const cancel = document.getElementById('filtron-modal-cancel');
			const modalForm = document.getElementById('filtron-modal-form');
			if (cancel) {
				cancel.addEventListener('click', () => this.modal && this.modal.close());
			}
			if (modalForm) {
				modalForm.addEventListener('submit', (e) => {
					e.preventDefault();
					this.createFromModal();
				});
			}

			const typeMain = document.getElementById('filtron-field-type');
			if (typeMain) {
				typeMain.addEventListener('change', () => {
					if (!this.selected) return;
					this.readFormIntoItem(this.selected);
					this.renderTypeFields(this.selected);
					this.updatePreview();
				});
			}

			['filtron-field-label', 'filtron-field-source-type', 'filtron-field-source-key', 'filtron-field-active'].forEach((id) => {
				const el = document.getElementById(id);
				if (el) {
					el.addEventListener('input', () => {
						if (this.selected) {
							this.readFormIntoItem(this.selected);
							this.updatePreview();
						}
					});
					el.addEventListener('change', () => {
						if (this.selected) {
							this.readFormIntoItem(this.selected);
							this.updatePreview();
						}
					});
				}
			});

			if (this.btnSave) {
				this.btnSave.addEventListener('click', () => this.saveCurrent());
			}

			document.addEventListener('keydown', (e) => {
				if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
					e.preventDefault();
					this.saveCurrent();
				}
			});
		}

		async createFromModal() {
			const label = document.getElementById('filtron-modal-label');
			const type = document.getElementById('filtron-modal-type');
			const st = document.getElementById('filtron-modal-source-type');
			const sk = document.getElementById('filtron-modal-source-key');
			if (!label || !type || !st || !sk) return;

			const item = {
				id: 0,
				group_id: parseInt(this.groupId, 10),
				label: label.value.trim(),
				filter_type: type.value,
				source_type: st.value,
				source_key: sk.value.trim(),
				config: defaultConfigForType({ filter_type: type.value }),
				is_active: 1,
			};

			if (!item.label || !item.source_key) return;

			if (item.filter_type === 'swatch' && !cfg().isPro) {
				toast((cfg().i18n && cfg().i18n.proDisabled) || 'Pro required', true);
				return;
			}

			try {
				const data = await postAjax('filtron_save_filter', {
					group_id: this.groupId,
					filter: JSON.stringify(item),
				});
				const saved = data.filter;
				if (saved) {
					this.items.push(saved);
					label.value = '';
					sk.value = '';
					this.modal.close();
					this.renderList();
					this.updateEmptyState();
					this.selectItem(saved.id);
					toast((cfg().i18n && cfg().i18n.saved) || 'Saved', false);
				}
			} catch (e) {
				toast(e.message, true);
			}
		}

		async saveCurrent() {
			if (!this.selected) {
				toast('Select a filter first', true);
				return;
			}
			this.readFormIntoItem(this.selected);
			const payload = {
				id: this.selected.id,
				group_id: this.selected.group_id,
				label: this.selected.label,
				filter_type: this.selected.filter_type,
				source_type: this.selected.source_type,
				source_key: this.selected.source_key,
				config: this.selected.config || {},
				is_active: this.selected.is_active,
			};

			try {
				const data = await postAjax('filtron_save_filter', {
					group_id: this.groupId,
					filter: JSON.stringify(payload),
				});
				const saved = data.filter;
				if (saved) {
					const idx = this.items.findIndex((x) => x.id === saved.id);
					if (idx >= 0) this.items[idx] = saved;
					this.selected = saved;
					this.renderList();
					this.populateForm(saved);
					this.renderTypeFields(saved);
					this.updatePreview();
					toast((cfg().i18n && cfg().i18n.saved) || 'Saved', false);
				}
			} catch (e) {
				toast(e.message, true);
			}
		}

		clearEditor() {
			this.selected = null;
			if (this.editorEl) this.editorEl.hidden = true;
			if (this.previewEl) this.previewEl.innerHTML = '';
			const idEl = document.getElementById('filtron-item-id');
			if (idEl) idEl.value = '';
		}

		/**
		 * @param {FiltronFilterItem} item
		 * @returns {Record<string, unknown>}
		 */
		buildSavePayload(item) {
			return {
				id: item.id,
				group_id: item.group_id,
				label: item.label,
				filter_type: item.filter_type,
				source_type: item.source_type,
				source_key: item.source_key,
				config: item.config || {},
				is_active: item.is_active,
			};
		}

		/**
		 * @param {number} id
		 */
		async toggleItemActive(id) {
			const item = this.items.find((x) => x.id === id);
			if (!item) return;
			this.pendingDeleteId = null;
			const previous = item.is_active !== 0 ? 1 : 0;
			item.is_active = previous ? 0 : 1;

			try {
				const data = await postAjax('filtron_save_filter', {
					group_id: this.groupId,
					filter: JSON.stringify(this.buildSavePayload(item)),
				});
				const saved = data.filter;
				if (saved) {
					const idx = this.items.findIndex((x) => x.id === saved.id);
					if (idx >= 0) this.items[idx] = saved;
					if (this.selected && this.selected.id === saved.id) {
						this.selected = saved;
						this.populateForm(saved);
						this.renderTypeFields(saved);
						this.updatePreview();
					}
					this.renderList();
					toast((cfg().i18n && cfg().i18n.saved) || 'Saved', false);
				}
			} catch (e) {
				item.is_active = previous;
				this.renderList();
				toast(e.message, true);
			}
		}

		/**
		 * @param {number} id
		 */
		async deleteItem(id) {
			const item = this.items.find((x) => x.id === id);
			if (!item) return;

			const i18n = cfg().i18n || {};
			if (this.pendingDeleteId !== id) {
				this.pendingDeleteId = id;
				this.renderList();
				return;
			}

			try {
				await postAjax('filtron_delete_filter', {
					group_id: this.groupId,
					item_id: String(id),
				});
				this.pendingDeleteId = null;
				this.items = this.items.filter((x) => x.id !== id);
				if (this.selected && this.selected.id === id) {
					this.clearEditor();
				}
				this.renderList();
				this.updateEmptyState();
				toast(i18n.deleted || 'Filter deleted.', false);
			} catch (e) {
				this.pendingDeleteId = null;
				this.renderList();
				toast(e.message, true);
			}
		}
	}

	/**
	 * @typedef {Object} FiltronFilterItem
	 * @property {number} id
	 * @property {number} [group_id]
	 * @property {string} filter_type
	 * @property {string} source_type
	 * @property {string} source_key
	 * @property {string} label
	 * @property {Record<string, unknown>} [config]
	 * @property {number} [is_active]
	 */

	/**
	 * @typedef {Object} FiltronAdminConfig
	 * @property {string} [ajax_url]
	 * @property {string} [nonce]
	 * @property {number|string} [groupId]
	 * @property {boolean} [isPro]
	 * @property {Record<string, string>} [i18n]
	 */

	function boot() {
		const app = new FiltronAdminApp();
		app.start();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(typeof window !== 'undefined' ? window : globalThis);
