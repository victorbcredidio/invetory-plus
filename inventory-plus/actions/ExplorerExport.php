<?php declare(strict_types = 0);
/*
** Inventory Plus - Explorer export.
**
** Returns the full (unpaged) result of the saved Explorer filter as JSON; the browser turns it into a CSV file.
** Using layout.json keeps the export identical across Zabbix 7.0, 7.4 and 8.0 (8.0 replaced layout.csv).
**/

namespace Modules\InventoryPlus\Actions;

use CController,
	CControllerResponseData,
	CProfile,
	CRoleHelper;

use Modules\InventoryPlus\Includes\ExplorerData,
	Modules\InventoryPlus\Includes\InventoryFields;

class ExplorerExport extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'columns_mode' => 'in visible,all'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => _('Invalid request.')
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS);
	}

	protected function doAction(): void {
		$p = ExplorerData::PROFILE;

		$sort = CProfile::get($p.'.sort', 'name');
		$sortorder = CProfile::get($p.'.sortorder', ZBX_SORT_UP);

		if ($sort !== 'name' && !InventoryFields::isColumn($sort)) {
			$sort = 'name';
		}

		$filter = ExplorerData::getFilter();

		$columns = $this->getInput('columns_mode', 'visible') === 'all'
			? array_merge(array_keys(InventoryFields::getVirtualColumns()), InventoryFields::getFieldNames())
			: ExplorerData::getColumns();

		$dataset = ExplorerData::build($filter, $columns, $sort, $sortorder);

		if (!$dataset['added_on_allowed']) {
			$columns = array_values(array_diff($columns, [InventoryFields::COL_ADDED_ON]));
		}

		$field_count = count(InventoryFields::getFieldNames());
		$modes = InventoryFields::getInventoryModes();

		$header = [_('Host'), _('Visible name')];

		foreach ($columns as $column) {
			$header[] = InventoryFields::getTitle($column);
		}

		$rows = [];

		foreach ($dataset['hosts'] as $host) {
			$row = [$host['host'], $host['name']];

			foreach ($columns as $column) {
				switch ($column) {
					case InventoryFields::COL_GROUPS:
						$row[] = implode(', ', array_column($host['hostgroups'], 'name'));
						break;

					case InventoryFields::COL_STATUS:
						$row[] = $host['status'] == HOST_STATUS_MONITORED ? _('Enabled') : _('Disabled');
						break;

					case InventoryFields::COL_INVENTORY_MODE:
						$row[] = array_key_exists($host['inventory_mode'], $modes) ? $modes[$host['inventory_mode']] : '';
						break;

					case InventoryFields::COL_ADDED_ON:
						$row[] = $host['added_on'] !== null ? date('Y-m-d H:i:s', $host['added_on']['clock']) : '';
						break;

					case InventoryFields::COL_COMPLETENESS:
						$row[] = $host['inventory_mode'] == HOST_INVENTORY_DISABLED
							? ''
							: round($host['filled'] / $field_count * 100).'%';
						break;

					case InventoryFields::COL_TAGS:
						$row[] = implode(', ', array_map(static function ($tag) {
							return $tag['value'] === '' ? $tag['tag'] : $tag['tag'].': '.$tag['value'];
						}, $host['tags']));
						break;

					default:
						$row[] = $host['inventory'][$column];
				}
			}

			$rows[] = $row;
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'filename' => 'zbx_inventory_'.date('Ymd_His').'.csv',
			'header' => $header,
			'rows' => $rows
		])]));
	}
}
