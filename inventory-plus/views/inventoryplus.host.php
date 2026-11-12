<?php declare(strict_types = 0);
/*
** Inventory Plus - Host inventory card view.
**
** @var CView $this
** @var array $data
**/

use Modules\InventoryPlus\Includes\InventoryFields,
	Modules\InventoryPlus\Includes\Render;

$this->addCssFile($this->getAssetsPath().'/css/inventory-plus.css?v=1.1.0');

if ($data['location'] !== null) {
	$this->addJsFile('leaflet.js');
	$this->addCssFile('assets/styles/vendors/Leaflet/leaflet.css');
}

$this->includeJsFile('inventoryplus.host.js.php');

$host = $data['host'];
$hostid = $host['hostid'];
$inventory = $data['inventory'];
$inventory_disabled = $host['inventory_mode'] == HOST_INVENTORY_DISABLED;

// Header controls.
$controls = (new CList())
	->addItem(
		(new CLink(_('Back to Explorer'), (new CUrl('zabbix.php'))
			->setArgument('action', 'inventoryplus.explorer')
			->getUrl()
		))->addClass('btn-alt')->addClass('ip-btn-link')
	);

if ($data['allowed_ui_problems']) {
	$controls->addItem(
		(new CLink(_('Problems'), (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('filter_set', '1')
			->setArgument('hostids', [$hostid])
			->getUrl()
		))->addClass('btn-alt')->addClass('ip-btn-link')
	);
}

if ($data['allowed_ui_latest_data']) {
	$controls->addItem(
		(new CLink(_('Latest data'), (new CUrl('zabbix.php'))
			->setArgument('action', 'latest.view')
			->setArgument('filter_set', '1')
			->setArgument('hostids', [$hostid])
			->getUrl()
		))->addClass('btn-alt')->addClass('ip-btn-link')
	);
}

if ($data['allowed_ui_conf_hosts']) {
	$controls->addItem(
		(new CLink(_('Configure host'), $data['host_edit_url']))->addClass('btn-alt')->addClass('ip-btn-link')
	);
}

// Hero / summary strip.
$problem_chips = [];

foreach ($data['problems_by_severity'] as $severity => $count) {
	$problem_chips[] = (new CSpan($count))
		->addClass('ip-sev-chip')
		->addClass(CSeverityHelper::getStyle($severity))
		->setTitle(CSeverityHelper::getName($severity));
}

$facts = (new CDiv())->addClass('ip-facts');

$fact = static function (string $label, $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('ip-fact-label'),
		(new CDiv($value))->addClass('ip-fact-value')
	]))->addClass('ip-fact');
};

$facts->addItem($fact(_('Status'), Render::status((int) $host['status'])));
$facts->addItem($fact(_('Inventory mode'), Render::inventoryMode((int) $host['inventory_mode'])));

if ($data['added_on_allowed']) {
	$facts->addItem($fact(_('Added on'), Render::addedOn($data['added_on'], $data['oldest_audit'],
		CWebUser::getType() == USER_TYPE_SUPER_ADMIN
	)));
}

$facts->addItem($fact(_('Completeness'), $inventory_disabled
	? (new CSpan(_('Inventory disabled')))->addClass(ZBX_STYLE_GREY)
	: Render::meter($data['filled'], $data['field_count'])
));

$facts->addItem($fact(_('Problems'), $problem_chips
	? $problem_chips
	: (new CSpan(_('None')))->addClass(ZBX_STYLE_GREEN)
));

$hero_items = [
	(new CDiv([
		(new CTag('h2', true, $host['name']))->addClass('ip-hero-title'),
		$host['host'] !== $host['name'] ? (new CDiv($host['host']))->addClass('ip-subtle') : null
	]))->addClass('ip-hero-name'),
	$facts
];

$hero_meta = (new CDiv())->addClass('ip-hero-meta');

if ($host['hostgroups']) {
	$hero_meta->addItem((new CDiv([
		(new CSpan(_('Host groups').': '))->addClass('ip-subtle'),
		implode(', ', array_column($host['hostgroups'], 'name'))
	]))->addClass('ip-hero-line'));
}

if ($host['tags']) {
	$hero_meta->addItem((new CDiv([
		(new CSpan(_('Tags').': '))->addClass('ip-subtle'),
		Render::tags($host['tags'])
	]))->addClass('ip-hero-line'));
}

if (trim($host['description']) !== '') {
	$hero_meta->addItem((new CDiv($host['description']))->addClass('ip-hero-line')->addClass('ip-pre-wrap'));
}

$hero_items[] = $hero_meta;

$hero = (new CDiv($hero_items))->addClass('ip-panel')->addClass('ip-hero');

// Section cards.
$cards = (new CDiv())->addClass('ip-cards')->setId('ip-cards');

foreach ($data['fields_by_section'] as $section => $fields) {
	$rows = (new CDiv())->addClass('ip-card-rows');
	$filled = 0;

	foreach ($fields as $field => $title) {
		$value = $inventory[$field];
		$is_empty = trim($value) === '';
		$filled += $is_empty ? 0 : 1;

		$label = [new CSpan($title)];

		if (array_key_exists($field, $data['auto_fields'])) {
			$label[] = (new CSpan(_('item')))
				->addClass('ip-badge')
				->addClass('ip-badge-auto')
				->setTitle(_s('Populated automatically by item "%1$s"', $data['auto_fields'][$field]));
		}

		$rows->addItem(
			(new CDiv([
				(new CDiv($label))->addClass('ip-card-label'),
				(new CDiv($is_empty ? '—' : Render::value($field, $value)))->addClass('ip-card-value')
			]))
				->addClass('ip-card-row')
				->addClass($is_empty ? 'ip-field-empty' : null)
		);
	}

	$card_body = [$filled == 0 ? (new CDiv(_('No data')))->addClass('ip-card-nodata') : null, $rows];

	if ($section === InventoryFields::SECTION_LOCATION && $data['location'] !== null) {
		array_unshift($card_body, (new CDiv())->setId('ip-map')->addClass('ip-map'));
	}

	$card = (new CDiv([
		(new CDiv([
			new CTag('h4', true, $data['sections'][$section]),
			(new CSpan($filled.' / '.count($fields)))->addClass('ip-subtle')
		]))->addClass('ip-panel-header'),
		$card_body
	]))
		->addClass('ip-panel')
		->addClass('ip-card')
		->addClass('ip-card-'.$section)
		->addClass($filled == 0 ? 'ip-card-empty' : null);

	$cards->addItem($card);
}

// Monitoring card.
$interface_types = [
	INTERFACE_TYPE_AGENT => _('Agent'),
	INTERFACE_TYPE_SNMP => _('SNMP'),
	INTERFACE_TYPE_IPMI => _('IPMI'),
	INTERFACE_TYPE_JMX => _('JMX')
];

$monitoring_rows = (new CDiv())->addClass('ip-card-rows');

foreach ($host['interfaces'] as $interface) {
	$address = $interface['useip'] == INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'];

	$monitoring_rows->addItem((new CDiv([
		(new CDiv(array_key_exists($interface['type'], $interface_types)
			? $interface_types[$interface['type']]
			: _('Interface')
		))->addClass('ip-card-label'),
		(new CDiv($address.':'.$interface['port']))->addClass('ip-card-value')
	]))->addClass('ip-card-row'));
}

if (!$host['interfaces']) {
	$monitoring_rows->addItem((new CDiv([
		(new CDiv(_('Interfaces')))->addClass('ip-card-label'),
		(new CDiv(_('None')))->addClass('ip-card-value')->addClass(ZBX_STYLE_GREY)
	]))->addClass('ip-card-row'));
}

$monitoring_rows->addItem((new CDiv([
	(new CDiv(_('Items')))->addClass('ip-card-label'),
	(new CDiv($host['items']))->addClass('ip-card-value')
]))->addClass('ip-card-row'));

$monitoring_rows->addItem((new CDiv([
	(new CDiv(_('Triggers')))->addClass('ip-card-label'),
	(new CDiv($host['triggers']))->addClass('ip-card-value')
]))->addClass('ip-card-row'));

$cards->addItem(
	(new CDiv([
		(new CDiv(new CTag('h4', true, _('Monitoring'))))->addClass('ip-panel-header'),
		$monitoring_rows
	]))
		->addClass('ip-panel')
		->addClass('ip-card')
		->addClass('ip-card-monitoring')
);

$toolbar = (new CDiv([
	(new CCheckBox('ip_show_empty'))->setId('ip-show-empty-fields'),
	new CLabel(_('Show empty fields'), 'ip-show-empty-fields'),
	$inventory_disabled
		? (new CSpan(_('Inventory is disabled for this host, so no inventory data is stored.')))
			->addClass('ip-subtle')
		: null
]))->addClass('ip-card-toolbar');

(new CHtmlPage())
	->setTitle(_('Host inventory'))
	->setDocUrl(CDocHelper::getUrl(CDocHelper::INVENTORY_HOST_LIST))
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem($hero)
	->addItem($toolbar)
	->addItem($cards)
	->show();

(new CScriptTag('view.init('.json_encode([
	'location' => $data['location'],
	'map_config' => $data['map_config'],
	'marker_title' => $host['name']
]).');'))
	->setOnDocumentReady()
	->show();
