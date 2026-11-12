<?php declare(strict_types = 0);
/*
** Inventory Plus - Analytics view.
**
** @var CView $this
** @var array $data
**/

use Modules\InventoryPlus\Includes\InventoryFields,
	Modules\InventoryPlus\Includes\InventoryQuery,
	Modules\InventoryPlus\Includes\Render;

$this->addCssFile($this->getAssetsPath().'/css/inventory-plus.css?v=1.1.0');
$this->includeJsFile('inventoryplus.analytics.js.php');

$options = $data['options'];
$kpi = $data['kpi'];
$field_title = InventoryFields::getTitle($options['field']);

$analytics_url = static function (array $args = []): string {
	$url = (new CUrl('zabbix.php'))->setArgument('action', 'inventoryplus.analytics');

	foreach ($args as $key => $value) {
		$url->setArgument($key, $value);
	}

	return $url->getUrl();
};

// Drill-down to the Explorer keeps the same scope (groups, status, tags) plus the given inventory conditions.
$drill_extra = [
	'filter_status' => $data['filter']['status'],
	'filter_inventory_mode' => InventoryQuery::MODE_ENABLED,
	'filter_evaltype' => $data['filter']['evaltype']
];

if ($data['filter']['tags']) {
	$drill_extra['filter_tags'] = $data['filter']['tags'];
}

$drill = static function (array $conditions) use ($data, $drill_extra): ?string {
	return $data['allowed_ui_inventory_hosts']
		? Render::explorerUrl($data['filter']['groupids'], $conditions, $drill_extra)
		: null;
};

$link_or_text = static function ($label, ?string $url) {
	return $url !== null ? new CLink($label, $url) : new CSpan($label);
};

$condition_for = static function (string $field, string $label): array {
	return $label === ''
		? ['field' => $field, 'operator' => InventoryQuery::OP_EMPTY, 'value' => '']
		: ['field' => $field, 'operator' => InventoryQuery::OP_EQUALS, 'value' => $label];
};

// Scope filter.
$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'inventoryplus.analytics'))
	->setProfile($data['profileIdx'])
	->setActiveTab($data['active_tab'])
	->addVar('action', 'inventoryplus.analytics')
	->addFilterTab(_('Filter'), [
		(new CFormGrid())
			->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
			->addItem([
				new CLabel(_('Host groups'), 'filter_groupids__ms'),
				new CFormField(
					(new CMultiSelect([
						'name' => 'filter_groupids[]',
						'object_name' => 'hostGroup',
						'data' => $data['ms_groups'],
						'popup' => [
							'parameters' => [
								'srctbl' => 'host_groups',
								'srcfld1' => 'groupid',
								'dstfrm' => 'zbx_filter',
								'dstfld1' => 'filter_groupids_',
								'with_hosts' => true,
								'enrich_parent_groups' => true
							]
						]
					]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				)
			])
			->addItem([
				new CLabel(_('Status'), 'filter_status'),
				new CFormField(
					(new CRadioButtonList('filter_status', (int) $data['filter']['status']))
						->addValue(_('Any'), -1)
						->addValue(_('Enabled'), HOST_STATUS_MONITORED)
						->addValue(_('Disabled'), HOST_STATUS_NOT_MONITORED)
						->setModern(true)
				)
			]),
		(new CFormGrid())
			->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
			->addItem([
				new CLabel(_('Tags')),
				new CFormField(
					CTagFilterFieldHelper::getTagFilterField([
						'evaltype' => $data['filter']['evaltype'],
						'tags' => $data['filter']['tags']
					])
				)
			])
	]);

// "What to analyze" toolbar.
$toolbar = (new CForm('get', 'zabbix.php'))
	->setName('ip_analytics_options')
	->addClass('ip-toolbar')
	->addVar('action', 'inventoryplus.analytics')
	->addVar('view_set', '1')
	->addItem([
		(new CDiv([
			new CLabel(_('Group by'), 'label-ip-field'),
			Render::addFieldOptions(new CSelect('field'))
				->setId('ip-field')
				->setFocusableElementId('label-ip-field')
				->setValue($options['field'])
				->addClass('js-autosubmit')
		]))->addClass('ip-toolbar-item'),
		(new CDiv([
			new CLabel(_('Then by'), 'label-ip-field2'),
			Render::addFieldOptions(new CSelect('field2'), true)
				->setId('ip-field2')
				->setFocusableElementId('label-ip-field2')
				->setValue($options['field2'])
				->addClass('js-autosubmit')
		]))->addClass('ip-toolbar-item'),
		(new CDiv([
			new CLabel(_('Show top'), 'label-ip-top'),
			(new CSelect('top'))
				->setId('ip-top')
				->setFocusableElementId('label-ip-top')
				->addOptions(CSelect::createOptionsFromArray(array_combine($data['top_options'], array_map(
					static function ($top) {
						return $top == 0 ? _('All') : (string) $top;
					},
					$data['top_options']
				))))
				->setValue($options['top'])
				->addClass('js-autosubmit')
		]))->addClass('ip-toolbar-item'),
		(new CDiv([
			new CVar('normalize', '0'),
			(new CCheckBox('normalize', '1'))
				->setId('ip-normalize')
				->setChecked($options['normalize'] == 1)
				->addClass('js-autosubmit'),
			(new CLabel(_('Merge spelling variants'), 'ip-normalize'))
				->setTitle(_('Treat values that differ only in letter case or spacing as the same value.'))
		]))->addClass('ip-toolbar-item')->addClass('ip-toolbar-check'),
		(new CDiv([
			new CVar('show_empty', '0'),
			(new CCheckBox('show_empty', '1'))
				->setId('ip-show-empty')
				->setChecked($options['show_empty'] == 1)
				->addClass('js-autosubmit'),
			new CLabel(_('Show "Not set"'), 'ip-show-empty')
		]))->addClass('ip-toolbar-item')->addClass('ip-toolbar-check')
	]);

// KPI tiles.
$tile = static function (string $label, $value, string $note = '', string $class = ''): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('ip-kpi-label'),
		(new CDiv($value))->addClass('ip-kpi-value'),
		$note !== '' ? (new CDiv($note))->addClass('ip-kpi-note') : null
	]))->addClass('ip-kpi')->addClass($class);
};

$kpis = (new CDiv([
	$tile(_('Hosts in scope'), $kpi['hosts'],
		$kpi['disabled'] > 0 ? _n('%1$s with inventory disabled', '%1$s with inventory disabled', $kpi['disabled']) : ''
	),
	$tile(_('Average completeness'), $kpi['avg_completeness'].'%',
		_s('of %1$s fields, hosts with inventory enabled', $data['field_count'])
	),
	$tile(_s('Distinct "%1$s" values', $field_title), $kpi['distinct'],
		$kpi['variant_groups'] > 0
			? _n('%1$s value has spelling variants', '%1$s values have spelling variants', $kpi['variant_groups'])
			: ''
	),
	$tile(_s('"%1$s" not set', $field_title), $kpi['not_set'],
		$kpi['enabled'] > 0 ? _s('%1$s%% of hosts with inventory enabled',
			(int) round($kpi['not_set'] / $kpi['enabled'] * 100)
		) : '',
		$kpi['not_set'] > 0 ? 'ip-kpi-warn' : ''
	)
]))->addClass('ip-kpis');

// Distribution panel.
$groups = $data['distribution']['groups'];
$limit = $options['top'];
$shown = $limit > 0 ? array_slice($groups, 0, $limit, true) : $groups;
$rest = $limit > 0 ? array_slice($groups, $limit, null, true) : [];
$max = 1;

foreach ($shown as $group) {
	$max = max($max, $group['count']);
}

if ($options['show_empty']) {
	$max = max($max, $data['distribution']['empty']);
}

$denominator = max(1, $kpi['enabled']);

$bar_row = static function ($label, int $count, ?string $url, array $extra_classes = [], $badge = null)
		use ($max, $denominator, $link_or_text): CDiv {
	$pct_of_max = round($count / $max * 100, 1);
	$share = round($count / $denominator * 100, 1);

	return (new CDiv([
		(new CDiv([$link_or_text($label, $url), $badge]))->addClass('ip-bar-label'),
		(new CDiv(
			(new CDiv())->addClass('ip-bar-fill')->addStyle('width: '.$pct_of_max.'%;')
		))->addClass('ip-bar-track'),
		(new CDiv($count))->addClass('ip-bar-count'),
		(new CDiv($share.'%'))->addClass('ip-bar-share')
	]))
		->addClass('ip-bar-row')
		->addClass(implode(' ', $extra_classes));
};

$bars = (new CDiv())->addClass('ip-bars');

if (!$groups && !($options['show_empty'] && $data['distribution']['empty'] > 0)) {
	$bars->addItem((new CDiv(_('No data found.')))->addClass('ip-empty'));
}

foreach ($shown as $group) {
	$badge = null;

	if (count($group['variants']) > 1) {
		$variant_lines = [];

		foreach ($group['variants'] as $variant => $count) {
			$variant_lines[] = '"'.$variant.'" ('.$count.')';
		}

		$badge = (new CSpan(_n('%1$s variant', '%1$s variants', count($group['variants']))))
			->addClass('ip-badge')
			->addClass('ip-badge-warn')
			->setTitle(_('Spelling variants').":\n".implode("\n", $variant_lines));
	}

	$bars->addItem($bar_row($group['label'], $group['count'],
		$drill([$condition_for($options['field'], $group['label'])]), [], $badge
	));
}

if ($rest) {
	$rest_count = array_sum(array_column($rest, 'count'));
	$bars->addItem($bar_row(_n('Other (%1$s value)', 'Other (%1$s values)', count($rest)), $rest_count, null,
		['ip-bar-other']
	));
}

if ($options['show_empty'] && $data['distribution']['empty'] > 0) {
	$bars->addItem($bar_row(_('Not set'), $data['distribution']['empty'],
		$drill([$condition_for($options['field'], '')]), ['ip-bar-empty']
	));
}

$distribution_panel = (new CDiv([
	(new CDiv([
		(new CTag('h4', true, _s('Hosts by %1$s', $field_title))),
		(new CSpan(_('Share is relative to hosts with inventory enabled.')))->addClass('ip-subtle')
	]))->addClass('ip-panel-header'),
	$bars
]))->addClass('ip-panel');

// Field fill rate panel.
$fill_list = (new CDiv())->addClass('ip-fill');

foreach ($data['fields_by_section'] as $section => $fields) {
	$fill_list->addItem((new CDiv($data['sections'][$section]))->addClass('ip-fill-section'));

	foreach ($fields as $field => $title) {
		$pct = $kpi['enabled'] > 0 ? (int) round($data['fill'][$field] / $kpi['enabled'] * 100) : 0;
		$level = $pct >= 60 ? 'ip-meter-good' : ($pct >= 30 ? 'ip-meter-fair' : 'ip-meter-poor');

		$fill_list->addItem(
			(new CDiv([
				(new CLink($title, $analytics_url(['field' => $field])))
					->addClass('ip-fill-name')
					->addClass($field === $options['field'] ? 'ip-selected' : null)
					->setTitle(_('Group by this field')),
				(new CDiv(
					(new CDiv())->addClass('ip-meter-fill')->addClass($level)->addStyle('width: '.$pct.'%;')
				))->addClass('ip-meter-track'),
				(new CSpan($pct.'%'))->addClass('ip-fill-pct')
			]))->addClass('ip-fill-row')
		);
	}
}

$fill_panel = (new CDiv([
	(new CDiv([
		new CTag('h4', true, _('Field fill rate')),
		(new CSpan(_('Click a field to group by it.')))->addClass('ip-subtle')
	]))->addClass('ip-panel-header'),
	$fill_list
]))->addClass('ip-panel')->addClass('ip-panel-fill');

// Cross-tab panel.
$crosstab_panel = null;

if ($data['crosstab'] !== null) {
	$ct = $data['crosstab'];
	$label_of = static function ($key, $label) {
		if ($key === "\0other") {
			return _('Other');
		}

		return $label === '' ? _('Not set') : $label;
	};

	$header = [(new CColHeader(
		InventoryFields::getTitle($options['field']).' \\ '.InventoryFields::getTitle($options['field2'])
	))->addClass('ip-ct-corner')];

	foreach ($ct['columns'] as $ckey => $clabel) {
		$header[] = (new CColHeader($label_of($ckey, $clabel)))->addClass('ip-ct-head');
	}

	$header[] = (new CColHeader(_('Total')))->addClass('ip-ct-head');

	$ct_table = (new CTable())
		->addClass('ip-crosstab')
		->setHeader($header);

	foreach ($ct['rows'] as $rkey => $rlabel) {
		$row = [(new CCol($label_of($rkey, $rlabel)))->addClass('ip-ct-rowhead')];
		$row_total = 0;

		foreach ($ct['columns'] as $ckey => $clabel) {
			$count = (array_key_exists($rkey, $ct['cells']) && array_key_exists($ckey, $ct['cells'][$rkey]))
				? $ct['cells'][$rkey][$ckey]
				: 0;
			$row_total += $count;

			if ($count == 0) {
				$row[] = (new CCol(''))->addClass('ip-ct-cell');
				continue;
			}

			$url = $ckey === "\0other"
				? null
				: $drill([
					$condition_for($options['field'], (string) $rlabel),
					$condition_for($options['field2'], (string) $clabel)
				]);

			$intensity = round(0.12 + 0.88 * $count / max(1, $ct['max']), 3);

			$row[] = (new CCol($link_or_text($count, $url)))
				->addClass('ip-ct-cell')
				->addClass($intensity > 0.55 ? 'ip-ct-strong' : null)
				->addStyle('--ip-i: '.$intensity.';')
				->setAttribute('data-heat', '1');
		}

		$row[] = (new CCol($row_total))->addClass('ip-ct-total');
		$ct_table->addRow($row);
	}

	$crosstab_panel = (new CDiv([
		(new CDiv([
			new CTag('h4', true, _s('%1$s by %2$s', InventoryFields::getTitle($options['field']),
				InventoryFields::getTitle($options['field2'])
			)),
			$ct['more_rows'] > 0
				? (new CSpan(_n('%1$s more row not shown', '%1$s more rows not shown', $ct['more_rows'])))
					->addClass('ip-subtle')
				: null
		]))->addClass('ip-panel-header'),
		(new CDiv($ct_table))->addClass('ip-crosstab-scroll')
	]))->addClass('ip-panel');
}

(new CHtmlPage())
	->setTitle(_('Inventory analytics'))
	->setDocUrl(CDocHelper::getUrl(CDocHelper::INVENTORY_HOST_OVERVIEW))
	->addItem($filter)
	->addItem($toolbar)
	->addItem($kpis)
	->addItem($crosstab_panel)
	->addItem(
		(new CDiv([$distribution_panel, $fill_panel]))->addClass('ip-analytics-grid')
	)
	->show();

(new CScriptTag('view.init();'))
	->setOnDocumentReady()
	->show();
