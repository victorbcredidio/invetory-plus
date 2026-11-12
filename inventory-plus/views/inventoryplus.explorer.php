<?php declare(strict_types = 0);
/*
** Inventory Plus - Explorer view.
**
** @var CView $this
** @var array $data
**/

use Modules\InventoryPlus\Includes\InventoryFields,
	Modules\InventoryPlus\Includes\InventoryQuery,
	Modules\InventoryPlus\Includes\Render;

$this->addCssFile($this->getAssetsPath().'/css/inventory-plus.css?v=1.1.0');
$this->includeJsFile('inventoryplus.explorer.js.php');

// Inventory condition rows.
$conditions_table = (new CTable())
	->setId('filter-conditions')
	->addClass('ip-conditions');

$conditions_table->addRow(
	(new CCol(
		(new CRadioButtonList('filter_cond_evaltype', (int) $data['filter']['cond_evaltype']))
			->addValue(_('And'), InventoryQuery::EVAL_AND)
			->addValue(_('Or'), InventoryQuery::EVAL_OR)
			->setModern(true)
			->setId('filter_cond_evaltype')
	))->setColSpan(4)
);

$make_condition_row = static function ($index, array $condition): CRow {
	return (new CRow([
		Render::addFieldOptions(new CSelect('filter_conditions['.$index.'][field]'))
			->setValue($condition['field'])
			->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
			->addClass('js-cond-field'),
		(new CSelect('filter_conditions['.$index.'][operator]'))
			->addOptions(CSelect::createOptionsFromArray(InventoryQuery::getOperators()))
			->setValue($condition['operator'])
			->addClass('js-cond-operator'),
		(new CTextBox('filter_conditions['.$index.'][value]', $condition['value']))
			->setAttribute('placeholder', _('value'))
			->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
			->addClass('js-cond-value')
			->removeId(),
		(new CCol(
			(new CButton('filter_conditions['.$index.'][remove]', _('Remove')))
				->addClass(ZBX_STYLE_BTN_LINK)
				->addClass('element-table-remove')
				->removeId()
		))->addClass(ZBX_STYLE_NOWRAP)
	]))->addClass('form_row');
};

foreach ($data['filter']['conditions'] as $i => $condition) {
	$conditions_table->addRow($make_condition_row($i, $condition));
}

$conditions_table->addRow(
	(new CCol(
		(new CButton('conditions_add', _('Add')))
			->addClass(ZBX_STYLE_BTN_LINK)
			->addClass('element-table-add')
			->removeId()
	))->setColSpan(4)
);

$condition_template = $make_condition_row('#{rowNum}', [
	'field' => 'type',
	'operator' => InventoryQuery::OP_CONTAINS,
	'value' => ''
])->toString();

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'inventoryplus.explorer'))
	->setProfile($data['profileIdx'])
	->setActiveTab($data['active_tab'])
	->addVar('action', 'inventoryplus.explorer')
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
				new CLabel(_('Host name'), 'filter_name'),
				new CFormField(
					(new CTextBox('filter_name', $data['filter']['name']))
						->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
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
			])
			->addItem([
				new CLabel(_('Inventory mode'), 'label-filter-inventory-mode'),
				new CFormField(
					(new CSelect('filter_inventory_mode'))
						->setFocusableElementId('label-filter-inventory-mode')
						->addOptions(CSelect::createOptionsFromArray(InventoryQuery::getInventoryModeOptions()))
						->setValue($data['filter']['inventory_mode'])
				)
			])
			->addItem([
				new CLabel(_('Tags')),
				new CFormField(
					CTagFilterFieldHelper::getTagFilterField([
						'evaltype' => $data['filter']['evaltype'],
						'tags' => $data['filter']['tags']
					])
				)
			]),
		(new CFormGrid())
			->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
			->addItem([
				new CLabel(_('Inventory fields')),
				new CFormField([
					$conditions_table,
					(new CDiv(_('Comparisons ignore letter case and extra spaces.')))->addClass('ip-hint')
				])
			])
	]);

// Column list (works like the 8.0 table options popup, implemented locally so it also runs on 7.0 and 7.4).
// The popup itself is built in JavaScript from this catalogue; selection and order are saved in the user profile.
$virtual = $data['virtual_columns'];

if (!$data['added_on_allowed']) {
	unset($virtual[InventoryFields::COL_ADDED_ON]);
}

$columns_catalogue = [];

foreach ($virtual as $column => $title) {
	$columns_catalogue[] = ['id' => $column, 'title' => $title, 'section' => _('General')];
}

foreach ($data['fields_by_section'] as $section => $fields) {
	foreach ($fields as $field => $title) {
		$columns_catalogue[] = ['id' => $field, 'title' => $title, 'section' => $data['sections'][$section]];
	}
}

$column_chooser = (new CDiv())
	->setId('ip-column-chooser')
	->addClass('ip-popover')
	->setAttribute('role', 'dialog')
	->setAttribute('aria-label', _('Column list'))
	->addStyle('display: none;');

// Results table.
$header = [make_sorting_header(_('Host'), 'name', $data['sort'], $data['sortorder'], $data['view_url'])];

foreach ($data['columns'] as $column) {
	$header[] = in_array($column, [InventoryFields::COL_GROUPS, InventoryFields::COL_TAGS])
		? InventoryFields::getTitle($column)
		: make_sorting_header(InventoryFields::getTitle($column), $column, $data['sort'], $data['sortorder'],
			$data['view_url']
		);
}

$table = (new CTableInfo())
	->setHeader($header)
	->addClass('ip-explorer-table')
	->setPageNavigation($data['paging']);

$show_user = CWebUser::getType() == USER_TYPE_SUPER_ADMIN;

foreach ($data['hosts'] as $hostid => $host) {
	$name_cell = [
		(new CLink($host['name'], Render::hostCardUrl($hostid)))->addClass('ip-host-link')
	];

	if ($host['host'] !== $host['name']) {
		$name_cell[] = (new CDiv($host['host']))->addClass('ip-subtle');
	}

	$row = [(new CCol($name_cell))->addClass('ip-col-host')];

	foreach ($data['columns'] as $column) {
		switch ($column) {
			case InventoryFields::COL_GROUPS:
				$row[] = (new CCol(implode(', ', array_column($host['hostgroups'], 'name'))))->addClass('ip-col-wrap');
				break;

			case InventoryFields::COL_STATUS:
				$row[] = Render::status((int) $host['status']);
				break;

			case InventoryFields::COL_INVENTORY_MODE:
				$row[] = Render::inventoryMode((int) $host['inventory_mode']);
				break;

			case InventoryFields::COL_ADDED_ON:
				$row[] = Render::addedOn($host['added_on'], $data['oldest_audit'], $show_user);
				break;

			case InventoryFields::COL_COMPLETENESS:
				$row[] = $host['inventory_mode'] == HOST_INVENTORY_DISABLED
					? (new CSpan(_('Inventory disabled')))->addClass(ZBX_STYLE_GREY)
					: Render::meter($host['filled'], $data['field_count']);
				break;

			case InventoryFields::COL_TAGS:
				$row[] = (new CCol(Render::tags($host['tags'])))->addClass('ip-col-tags');
				break;

			default:
				$row[] = (new CCol(Render::value($column, $host['inventory'][$column], true)))->addClass('ip-col-value');
		}
	}

	$table->addRow($row);
}

// Summary line.
$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
$summary = [
	(new CSpan(_n('%1$s host matches', '%1$s hosts match', $data['total'])))->addClass('ip-summary-count')
];

if ($data['total'] > $search_limit) {
	$summary[] = (new CSpan(
		_s('The table shows the first %1$s (search limit); the export contains all of them.', $search_limit)
	))->addClass('ip-subtle');
}

if ($data['filter_active']) {
	$summary[] = (new CSpan(_('Filter active')))->addClass('ip-badge')->addClass('ip-badge-auto');
}

$table_options = (new CDiv([
	(new CSimpleButton(_s('Columns (%1$s)', count($data['columns']) + 1)))
		->addClass(ZBX_STYLE_BTN_ALT)
		->addClass(ZBX_ICON_COG_FILLED)
		->addClass('js-columns-toggle')
		->setAttribute('aria-haspopup', 'dialog')
		->setTitle(_('Choose which columns are displayed and their order')),
	$column_chooser
]))->addClass('ip-table-options');

(new CHtmlPage())
	->setTitle(_('Inventory explorer'))
	->setDocUrl(CDocHelper::getUrl(CDocHelper::INVENTORY_HOST_LIST))
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CSimpleButton(_('Export CSV')))
						->addClass('js-export')
						->setAttribute('data-columns-mode', 'visible')
						->setTitle(_('Export all matching hosts with the visible columns'))
				)
				->addItem(
					(new CSimpleButton(_('Export CSV (all fields)')))
						->addClass(ZBX_STYLE_BTN_ALT)
						->addClass('js-export')
						->setAttribute('data-columns-mode', 'all')
						->setTitle(_('Export all matching hosts with every inventory field'))
				)
		))->setAttribute('aria-label', _('Content controls'))
	)
	->addItem($filter)
	->addItem((new CDiv([(new CDiv($summary))->addClass('ip-summary-info'), $table_options]))->addClass('ip-summary'))
	->addItem($table)
	->show();

(new CScriptTag('
	view.init('.json_encode([
		'condition_template' => $condition_template,
		'condition_count' => count($data['filter']['conditions']),
		'export_url' => (new CUrl('zabbix.php'))
			->setArgument('action', 'inventoryplus.explorer.export')
			->getUrl(),
		'reset_columns_url' => (new CUrl('zabbix.php'))
			->setArgument('action', 'inventoryplus.explorer')
			->setArgument('columns_rst', '1')
			->getUrl(),
		'apply_columns_url' => (new CUrl('zabbix.php'))
			->setArgument('action', 'inventoryplus.explorer')
			->setArgument('columns_set', '1')
			->getUrl(),
		'columns_catalogue' => $columns_catalogue,
		'columns_selected' => $data['columns'],
		'value_free_operators' => [InventoryQuery::OP_EMPTY, InventoryQuery::OP_NOT_EMPTY],
		'messages' => [
			'export_failed' => _('Export failed.'),
			'exporting' => _('Exporting...'),
			'column_list' => _('Column list'),
			'displayed' => _('Displayed columns'),
			'available' => _('Available columns'),
			'drag_hint' => _('Drag to reorder'),
			'search' => _('Search columns'),
			'host' => _('Host'),
			'selected' => _('%1$s of %2$s displayed'),
			'apply' => _('Apply'),
			'cancel' => _('Cancel'),
			'reset' => _('Reset layout'),
			'reset_confirm' => _('Your column settings will be reset to the defaults.'),
			'no_match' => _('No columns match.')
		]
	]).');
'))
	->setOnDocumentReady()
	->show();
