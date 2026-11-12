<?php declare(strict_types = 0);
/*
** Inventory Plus - Explorer view JavaScript.
**
** @var CView $this
**/
?>

<script type="text/x-jquery-tmpl" id="filter-tag-row-tmpl">
	<?= CTagFilterFieldHelper::getTemplate() ?>
</script>

<script>
	const view = new class {

		init({condition_template, condition_count, export_url, reset_columns_url, apply_columns_url, columns_catalogue,
				columns_selected, value_free_operators, messages}) {
			this.export_url = export_url;
			this.reset_columns_url = reset_columns_url;
			this.apply_columns_url = apply_columns_url;
			this.columns_catalogue = columns_catalogue;
			this.columns_selected = columns_selected;
			this.value_free_operators = value_free_operators;
			this.messages = messages;

			this._initTagFilter();
			this._initConditions(condition_template, condition_count);
			this._initColumnChooser();
			this._initExport();
		}

		_initTagFilter() {
			const $tags = jQuery('#filter-tags');

			if ($tags.length === 0) {
				return;
			}

			$tags
				.dynamicRows({template: '#filter-tag-row-tmpl'})
				.on('afteradd.dynamicRows', function () {
					if (typeof CTagFilterItem === 'function') {
						const rows = this.querySelectorAll('.form_row');
						new CTagFilterItem(rows[rows.length - 1]);
					}
				});

			if (typeof CTagFilterItem === 'function') {
				document.querySelectorAll('#filter-tags .form_row').forEach(row => new CTagFilterItem(row));
			}
		}

		_initConditions(template, count) {
			const table = document.getElementById('filter-conditions');

			if (table === null) {
				return;
			}

			const tmpl = document.createElement('script');
			tmpl.type = 'text/x-jquery-tmpl';
			tmpl.id = 'filter-condition-row-tmpl';
			tmpl.textContent = template;
			document.body.appendChild(tmpl);

			jQuery(table)
				.dynamicRows({template: '#filter-condition-row-tmpl', counter: count, allow_empty: true})
				.on('afteradd.dynamicRows', () => this._syncConditionRows());

			table.addEventListener('change', (e) => {
				if (e.target.classList.contains('js-cond-operator')) {
					this._syncConditionRows();
				}
			});

			this._syncConditionRows();
		}

		// Disable the value box for operators that do not need a value ("is empty", "is not empty").
		_syncConditionRows() {
			document.querySelectorAll('#filter-conditions .form_row').forEach(row => {
				const operator = row.querySelector('.js-cond-operator');
				const value = row.querySelector('.js-cond-value');

				if (operator === null || value === null) {
					return;
				}

				const value_free = this.value_free_operators.includes(operator.value);

				value.disabled = value_free;

				if (value_free) {
					value.value = '';
				}
			});
		}

		/*
		 * Column list popup, modelled on the Zabbix 8.0 table options popup but self-contained, so it also works on
		 * 7.0 and 7.4: every inventory field has a checkbox, displayed columns can be reordered by dragging,
		 * the list can be searched, and the layout can be reset to the defaults.
		 */
		_initColumnChooser() {
			this.chooser = document.getElementById('ip-column-chooser');
			this.chooser_toggle = document.querySelector('.js-columns-toggle');

			if (this.chooser === null || this.chooser_toggle === null) {
				return;
			}

			this.chooser_toggle.setAttribute('aria-expanded', 'false');

			this.chooser_toggle.addEventListener('click', (e) => {
				e.stopPropagation();
				this._isChooserOpen() ? this._closeChooser() : this._openChooser();
			});

			document.addEventListener('mousedown', (e) => {
				if (this._isChooserOpen() && !this.chooser.contains(e.target)
						&& !this.chooser_toggle.contains(e.target)) {
					this._closeChooser();
				}
			});

			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && this._isChooserOpen()) {
					this._closeChooser();
					this.chooser_toggle.focus();
				}
			});
		}

		_isChooserOpen() {
			return this.chooser.style.display !== 'none';
		}

		_openChooser() {
			// Always rebuilt from the saved state, so closing without "Apply" discards changes.
			this._buildChooser();
			this.chooser.style.display = '';
			this.chooser_toggle.setAttribute('aria-expanded', 'true');
			this.chooser.querySelector('.ip-col-search').focus();
		}

		_closeChooser() {
			this.chooser.style.display = 'none';
			this.chooser_toggle.setAttribute('aria-expanded', 'false');
		}

		_el(tag, class_name = '', text = null) {
			const node = document.createElement(tag);

			if (class_name !== '') {
				node.className = class_name;
			}

			if (text !== null) {
				node.textContent = text;
			}

			return node;
		}

		_buildChooser() {
			const m = this.messages;

			this.chooser.innerHTML = '';

			const header = this._el('div', 'ip-popover-header');
			header.append(this._el('div', 'ip-popover-title', m.column_list), this._el('div', 'ip-subtle js-col-count'));

			const search = this._el('input', 'ip-col-search');
			search.type = 'text';
			search.placeholder = m.search;
			search.setAttribute('aria-label', m.search);

			const catalogue = this.columns_catalogue.map((column, order) => ({...column, order}));
			const by_id = Object.fromEntries(catalogue.map(column => [column.id, column]));

			// Displayed columns: "Host" is always first and cannot be hidden, the rest keep the saved order.
			this.list_selected = this._el('ul', 'ip-col-list ip-col-selected');
			this.list_selected.append(this._makeColumnItem({id: '', title: m.host, section: '', order: -1}, true, true));

			for (const id of this.columns_selected) {
				if (id in by_id) {
					this.list_selected.append(this._makeColumnItem(by_id[id], true));
				}
			}

			// Available columns, grouped by inventory section in the native field order.
			const available = this._el('div', 'ip-col-available');
			this.available_lists = {};

			for (const column of catalogue) {
				if (!(column.section in this.available_lists)) {
					const list = this._el('ul', 'ip-col-list');

					list.dataset.section = column.section;
					this.available_lists[column.section] = list;
					available.append(this._el('div', 'ip-col-section-title', column.section), list);
				}

				if (!this.columns_selected.includes(column.id)) {
					this.available_lists[column.section].append(this._makeColumnItem(column, false));
				}
			}

			const no_match = this._el('div', 'ip-col-nomatch ip-subtle', m.no_match);
			no_match.style.display = 'none';

			const body = this._el('div', 'ip-popover-body');
			const displayed_title = this._el('div', 'ip-col-group-title', m.displayed);
			displayed_title.append(this._el('div', 'ip-subtle', m.drag_hint));

			body.append(displayed_title, this.list_selected, this._el('div', 'ip-col-group-title', m.available),
				available, no_match
			);

			const footer = this._el('div', 'ip-popover-footer');
			const apply = this._el('button', '', m.apply);
			const reset = this._el('button', 'btn-alt', m.reset);
			const cancel = this._el('button', 'btn-alt', m.cancel);

			[apply, reset, cancel].forEach(button => button.type = 'button');

			apply.addEventListener('click', () => this._applyColumns());
			reset.addEventListener('click', () => {
				if (confirm(m.reset_confirm)) {
					location.href = this.reset_columns_url;
				}
			});
			cancel.addEventListener('click', () => {
				this._closeChooser();
				this.chooser_toggle.focus();
			});

			footer.append(apply, reset, cancel);

			this.chooser.append(header, search, body, footer);

			this.col_search = search;
			this.col_no_match = no_match;

			search.addEventListener('input', () => this._filterColumns(search.value, no_match));
			search.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
				}
			});

			body.addEventListener('change', (e) => {
				if (e.target.matches('input[type="checkbox"]')) {
					this._toggleColumn(e.target.closest('li'), e.target.checked);
				}
			});

			this._initColumnDrag();
			this._updateColumnCount();
		}

		_makeColumnItem(column, checked, fixed = false) {
			const item = this._el('li', 'ip-col-item');
			const id = 'ip-col-' + (column.id === '' ? 'host' : column.id.replace(/^_+/, '').replace(/_/g, '-'));

			item.dataset.col = column.id;
			item.dataset.order = String(column.order);
			item.dataset.search = (column.title + ' ' + column.section + ' ' + column.id).toLowerCase();

			const handle = this._el('div', 'drag-icon ip-col-handle');

			const input = this._el('input', 'checkbox-radio');
			input.type = 'checkbox';
			input.id = id;
			input.value = '1';
			input.checked = checked;
			input.disabled = fixed;

			// Text must be in <div>s: Zabbix styles every <span> inside a checkbox label as the box itself.
			const label = this._el('label');
			label.htmlFor = id;
			label.append(document.createElement('span'), this._el('div', 'ip-col-name', column.title));

			if (column.section !== '') {
				label.append(this._el('div', 'ip-col-section', column.section));
			}

			item.append(handle, input, label);

			if (fixed) {
				item.classList.add('ip-col-fixed');
			}

			this._setItemDraggable(item, checked && !fixed);

			return item;
		}

		_setItemDraggable(item, draggable) {
			item.draggable = draggable;
			item.classList.toggle('ip-col-draggable', draggable);
		}

		// Checking moves a column to the end of the displayed list; unchecking returns it to its section.
		_toggleColumn(item, checked) {
			if (checked) {
				this.list_selected.append(item);
				this._setItemDraggable(item, true);
			}
			else {
				const column = this.columns_catalogue[Number(item.dataset.order)];
				const list = this.available_lists[column.section];
				const next = [...list.children].find(other => Number(other.dataset.order) > Number(item.dataset.order));

				list.insertBefore(item, next || null);
				this._setItemDraggable(item, false);
			}

			this._updateColumnCount();
		}

		_initColumnDrag() {
			const list = this.list_selected;
			let dragged = null;

			list.addEventListener('dragstart', (e) => {
				const item = e.target.closest('li.ip-col-draggable');

				if (item === null) {
					e.preventDefault();
					return;
				}

				dragged = item;
				item.classList.add('ip-col-dragging');
				e.dataTransfer.effectAllowed = 'move';
				// Firefox requires data to be set for the drag to start.
				e.dataTransfer.setData('text/plain', item.dataset.col);
			});

			list.addEventListener('dragover', (e) => {
				if (dragged === null) {
					return;
				}

				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';

				const target = e.target.closest('li');

				if (target === null || target === dragged || target.classList.contains('ip-col-fixed')) {
					return;
				}

				const rect = target.getBoundingClientRect();
				const after = e.clientY > rect.top + rect.height / 2;

				list.insertBefore(dragged, after ? target.nextSibling : target);
			});

			const finish = () => {
				if (dragged !== null) {
					dragged.classList.remove('ip-col-dragging');
					dragged = null;
				}
			};

			list.addEventListener('drop', (e) => {
				e.preventDefault();
				finish();
			});
			list.addEventListener('dragend', finish);
		}

		_filterColumns(query, no_match) {
			const needle = query.trim().toLowerCase();
			let visible = 0;

			this.chooser.querySelectorAll('.ip-col-item').forEach(item => {
				const match = needle === '' || item.classList.contains('ip-col-fixed')
					|| item.dataset.search.includes(needle);

				item.style.display = match ? '' : 'none';
				visible += match && !item.classList.contains('ip-col-fixed') ? 1 : 0;
			});

			// Hide section titles whose items are all filtered out (or all displayed).
			for (const list of Object.values(this.available_lists)) {
				const has_visible = [...list.children].some(item => item.style.display !== 'none');

				list.previousElementSibling.style.display = has_visible ? '' : 'none';
			}

			no_match.style.display = visible === 0 ? '' : 'none';
		}

		_updateColumnCount() {
			const shown = this.list_selected.querySelectorAll('li').length;
			const total = this.columns_catalogue.length + 1;

			this.chooser.querySelector('.js-col-count').textContent = this.messages.selected
				.replace('%1$s', shown)
				.replace('%2$s', total);

			// Re-apply the current search (also hides empty section titles).
			this._filterColumns(this.col_search.value, this.col_no_match);
		}

		_applyColumns() {
			const columns = [...this.list_selected.querySelectorAll('li')]
				.map(item => item.dataset.col)
				.filter(id => id !== '');

			let url = this.apply_columns_url;

			for (const column of columns) {
				url += '&columns[]=' + encodeURIComponent(column);
			}

			location.href = url;
		}

		_initExport() {
			document.querySelectorAll('.js-export').forEach(button => {
				button.addEventListener('click', () => this._export(button));
			});
		}

		_export(button) {
			const url = new Curl(this.export_url);
			url.setArgument('columns_mode', button.dataset.columnsMode);

			button.classList.add('is-loading');
			button.disabled = true;

			fetch(url.getUrl())
				.then(response => response.json())
				.then(response => {
					if ('error' in response) {
						throw new Error(typeof response.error === 'string' ? response.error : this.messages.export_failed);
					}

					const lines = [response.header, ...response.rows].map(row => row.map(view._csvCell).join(','));
					// BOM so that spreadsheet applications detect UTF-8.
					const blob = new Blob(['﻿' + lines.join('\r\n') + '\r\n'], {type: 'text/csv;charset=utf-8'});
					const link = document.createElement('a');

					link.href = URL.createObjectURL(blob);
					link.download = response.filename;
					document.body.appendChild(link);
					link.click();
					link.remove();
					setTimeout(() => URL.revokeObjectURL(link.href), 1000);
				})
				.catch(error => {
					clearMessages();
					addMessage(makeMessageBox('bad', [error.message || this.messages.export_failed]));
				})
				.finally(() => {
					button.classList.remove('is-loading');
					button.disabled = false;
				});
		}

		_csvCell(value) {
			let text = value === null || value === undefined ? '' : String(value);

			// Neutralize spreadsheet formula injection; negative numbers (e.g. coordinates) are left untouched.
			if (/^[=+@\t\r]/.test(text) || /^-[^0-9.]/.test(text)) {
				text = "'" + text;
			}

			return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
		}
	};
</script>
