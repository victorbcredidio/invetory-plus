<?php declare(strict_types = 0);
/*
** Inventory Plus - Host inventory card.
**/

namespace Modules\InventoryPlus\Actions;

use API,
	CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CRoleHelper,
	CRouter,
	CSettingsHelper,
	CUrl,
	CWebUser;

use Modules\InventoryPlus\Includes\AddedOn,
	Modules\InventoryPlus\Includes\ExplorerData,
	Modules\InventoryPlus\Includes\InventoryFields,
	Modules\InventoryPlus\Includes\InventoryQuery;

class HostCard extends CController {

	private $host;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'hostid' => 'required|db hosts.hostid'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!$this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)) {
			return false;
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'description', 'inventory_mode', 'maintenance_status'],
			'selectInventory' => InventoryFields::getFieldNames(),
			'selectHostGroups' => ['groupid', 'name'],
			'selectTags' => ['tag', 'value'],
			'selectInterfaces' => ['type', 'ip', 'dns', 'port', 'useip', 'main'],
			'selectItems' => API_OUTPUT_COUNT,
			'selectTriggers' => API_OUTPUT_COUNT,
			'hostids' => [$this->getInput('hostid')]
		]);

		if (!$hosts) {
			return false;
		}

		$this->host = reset($hosts);

		return true;
	}

	protected function doAction(): void {
		$host = $this->host;
		$hostid = $host['hostid'];

		$inventory = is_array($host['inventory']) ? $host['inventory'] : [];
		unset($inventory['hostid']);
		$inventory += array_fill_keys(InventoryFields::getFieldNames(), '');

		// Inventory fields populated by items (automatic inventory mode).
		$auto_fields = [];

		if ($host['inventory_mode'] == HOST_INVENTORY_AUTOMATIC) {
			$nr_to_field = [];

			foreach (InventoryFields::getFields() as $field => $meta) {
				$nr_to_field[$meta['nr']] = $field;
			}

			$items = API::Item()->get([
				'output' => ['itemid', 'name', 'inventory_link'],
				'hostids' => [$hostid],
				'filter' => ['inventory_link' => array_keys($nr_to_field)]
			]);

			foreach ($items as $item) {
				if (array_key_exists($item['inventory_link'], $nr_to_field)) {
					$auto_fields[$nr_to_field[$item['inventory_link']]] = $item['name'];
				}
			}
		}

		// Active problems by severity.
		$problems = API::Problem()->get([
			'output' => ['eventid', 'severity'],
			'hostids' => [$hostid]
		]);

		$problems_by_severity = [];

		foreach ($problems as $problem) {
			$severity = (int) $problem['severity'];
			$problems_by_severity[$severity] = array_key_exists($severity, $problems_by_severity)
				? $problems_by_severity[$severity] + 1
				: 1;
		}

		krsort($problems_by_severity);

		// Creation time from the audit log.
		$added_on_allowed = AddedOn::isAllowed((string) ExplorerData::getOption('added_on_visible_to', 'all'));
		$added_on = null;
		$oldest_audit = null;

		if ($added_on_allowed) {
			$records = AddedOn::getByHostIds([$hostid]);
			$added_on = array_key_exists($hostid, $records) ? $records[$hostid] : null;
			$oldest_audit = AddedOn::getOldestRecordClock();
		}

		// Coordinates for the map.
		$lat = trim($inventory['location_lat']);
		$lon = trim($inventory['location_lon']);
		$location = null;

		if (is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180) {
			$location = ['lat' => (float) $lat, 'lon' => (float) $lon];
		}

		$editable = CWebUser::getType() == USER_TYPE_SUPER_ADMIN
			|| (CWebUser::getType() == USER_TYPE_ZABBIX_ADMIN && (bool) API::Host()->get([
				'output' => [],
				'hostids' => [$hostid],
				'editable' => true
			]));

		$data = [
			'host' => $host,
			'inventory' => $inventory,
			'auto_fields' => $auto_fields,
			'filled' => InventoryQuery::countFilled($inventory),
			'field_count' => count(InventoryFields::getFieldNames()),
			'fields_by_section' => InventoryFields::getFieldsBySection(),
			'sections' => InventoryFields::getSections(),
			'problems_by_severity' => $problems_by_severity,
			'problem_count' => count($problems),
			'added_on_allowed' => $added_on_allowed,
			'added_on' => $added_on,
			'oldest_audit' => $oldest_audit,
			'location' => $location,
			'map_config' => $location !== null ? self::getMapConfig() : null,
			'allowed_ui_problems' => CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS),
			'allowed_ui_latest_data' => CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA),
			'allowed_ui_conf_hosts' => CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS) && $editable,
			'host_edit_url' => self::getHostEditUrl($hostid)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_s('Inventory: %1$s', $host['name']));
		$this->setResponse($response);
	}

	/**
	 * URL that opens the host configuration form as a standalone page.
	 *
	 * Zabbix 7.0 serves "host.edit" as a full HTML page. From 7.2 on (7.4, 8.0) "host.edit" returns JSON for the
	 * modal form and must be opened through the generic "popup" action instead. The router tells us which one
	 * applies, so no version number is hard-coded.
	 */
	private static function getHostEditUrl(string $hostid): string {
		$router = new CRouter();
		$router->setAction('host.edit');

		$url = new CUrl('zabbix.php');

		if ($router->getLayout() === 'layout.htmlpage') {
			$url->setArgument('action', 'host.edit');
		}
		else {
			$url
				->setArgument('action', 'popup')
				->setArgument('popup', 'host.edit');
		}

		return $url
			->setArgument('hostid', $hostid)
			->getUrl();
	}

	/**
	 * Tile server configuration, as configured in Administration > General > Geographical maps
	 * (same logic as the built-in Geomap widget).
	 */
	private static function getMapConfig(): array {
		if (CSettingsHelper::get(CSettingsHelper::GEOMAPS_TILE_PROVIDER) === '') {
			return [
				'tile_url' => CSettingsHelper::get(CSettingsHelper::GEOMAPS_TILE_URL),
				'max_zoom' => (int) CSettingsHelper::get(CSettingsHelper::GEOMAPS_MAX_ZOOM),
				'attribution' => htmlspecialchars(CSettingsHelper::get(CSettingsHelper::GEOMAPS_ATTRIBUTION),
					ENT_NOQUOTES, 'UTF-8'
				)
			];
		}

		$tile_providers = getTileProviders();
		$provider_id = CSettingsHelper::get(CSettingsHelper::GEOMAPS_TILE_PROVIDER);
		$provider = array_key_exists($provider_id, $tile_providers)
			? $tile_providers[$provider_id]
			: reset($tile_providers);

		return [
			'tile_url' => $provider['geomaps_tile_url'],
			'max_zoom' => (int) $provider['geomaps_max_zoom'],
			'attribution' => $provider['geomaps_attribution']
		];
	}
}
