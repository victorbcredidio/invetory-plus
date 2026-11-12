<?php declare(strict_types = 0);
/*
** Inventory Plus - Analytics view JavaScript.
**
** @var CView $this
**/
?>

<script type="text/x-jquery-tmpl" id="filter-tag-row-tmpl">
	<?= CTagFilterFieldHelper::getTemplate() ?>
</script>

<script>
	const view = new class {

		init() {
			this._initTagFilter();
			this._initToolbar();
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

		// Options toolbar applies immediately on change.
		_initToolbar() {
			const form = document.querySelector('form[name="ip_analytics_options"]');

			if (form === null) {
				return;
			}

			form.addEventListener('change', (e) => {
				if (e.target.closest('.js-autosubmit') !== null || e.target.classList.contains('js-autosubmit')) {
					form.submit();
				}
			});
		}
	};
</script>
