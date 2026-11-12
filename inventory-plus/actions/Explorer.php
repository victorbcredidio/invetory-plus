<?php declare(strict_types = 0);
/*
** Inventory Plus - Explorer page.
**/

namespace Modules\InventoryPlus\Actions;

use CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CPagerHelper,
	CProfile,
	CRoleHelper,
	CUrl,
	CWebUser;

use Modules\InventoryPlus\Includes\ExplorerData,
	Modules\InventoryPlus\Includes\InventoryFields,
	Modules\InventoryPlus\Includes\InventoryQuery;

class Explorer extends CController {

	protected function init(): void {
		// Read-only page; filter and column choices are stored in the user profile like built-in list pages do.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' =>				'in 1',
			'filter_rst' =>				'in 1',
			'filter_groupids' =>		'array_id',
			'filter_name' =>			'string',
			'filter_status' =>			'in -1,'.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'filter_inventory_mode' =>	'in '.implode(',', array_keys(InventoryQuery::getInventoryModeOptions())),
			'filter_evaltype' =>		'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' =>			'array',
			'filter_cond_evaltype' =>	'in '.InventoryQuery::EVAL_AND.','.InventoryQuery::EVAL_OR,
			'filter_conditions' =>		'array',
			'columns_set' =>			'in 1',
			'columns_rst' =>			'in 1',
			'columns' =>				'array',
			'sort' =>					'string',
			'sortorder' =>				'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'page' =>					'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS);
	}

	protected function doAction(): void {
		$p = ExplorerData::PROFILE;

		// Sorting.
		$sort = $this->getInput('sort', CProfile::get($p.'.sort', 'name'));
		$sortorder = $this->getInput('sortorder', CProfile::get($p.'.sortorder', ZBX_SORT_UP));

		if ($sort !== 'name' && !InventoryFields::isColumn($sort)) {
			$sort = 'name';
		}

		CProfile::update($p.'.sort', $sort, PROFILE_TYPE_STR);
		CProfile::update($p.'.sortorder', $sortorder, PROFILE_TYPE_STR);

		// Filter.
		if ($this->hasInput('filter_set')) {
			ExplorerData::updateFilter([
				'filter_groupids' => $this->getInput('filter_groupids', []),
				'filter_name' => $this->getInput('filter_name', ''),
				'filter_status' => $this->getInput('filter_status', -1),
				'filter_inventory_mode' => $this->getInput('filter_inventory_mode', InventoryQuery::MODE_ANY),
				'filter_evaltype' => $this->getInput('filter_evaltype', TAG_EVAL_TYPE_AND_OR),
				'filter_tags' => $this->getInput('filter_tags', []),
				'filter_cond_evaltype' => $this->getInput('filter_cond_evaltype', InventoryQuery::EVAL_AND),
				'filter_conditions' => $this->getInput('filter_conditions', [])
			]);
		}
		elseif ($this->hasInput('filter_rst')) {
			ExplorerData::resetFilter();
		}

		// Columns.
		if ($this->hasInput('columns_set')) {
			ExplorerData::setColumns($this->getInput('columns', []));
		}
		elseif ($this->hasInput('columns_rst')) {
			ExplorerData::resetColumns();
		}

		$filter = ExplorerData::getFilter();
		$columns = ExplorerData::getColumns();

		$dataset = ExplorerData::build($filter, $columns, $sort, $sortorder);

		if (!$dataset['added_on_allowed']) {
			$columns = array_values(array_diff($columns, [InventoryFields::COL_ADDED_ON]));
		}

		$hosts = $dataset['hosts'];
		$total = count($hosts);

		$view_url = (new CUrl('zabbix.php'))->setArgument('action', 'inventoryplus.explorer');

		// Rows are already sorted in the requested order, so the pager must not re-offset them.
		$page_num = $this->getInput('page', 1);
		CPagerHelper::savePage('inventoryplus.explorer', $page_num);
		$paging = CPagerHelper::paginate($page_num, $hosts, ZBX_SORT_UP, $view_url);

		$data = [
			'filter' => $filter,
			'filter_active' => ExplorerData::isFilterActive($filter),
			'ms_groups' => $dataset['ms_groups'],
			'profileIdx' => $p.'.filter',
			'active_tab' => CProfile::get($p.'.filter.active', 1),
			'columns' => $columns,
			'fields_by_section' => InventoryFields::getFieldsBySection(),
			'sections' => InventoryFields::getSections(),
			'virtual_columns' => InventoryFields::getVirtualColumns(),
			'added_on_allowed' => $dataset['added_on_allowed'],
			'oldest_audit' => $dataset['oldest_audit'],
			'field_count' => count(InventoryFields::getFieldNames()),
			'hosts' => $hosts,
			'total' => $total,
			'paging' => $paging,
			'sort' => $sort,
			'sortorder' => $sortorder,
			'view_url' => $view_url->getUrl(),
			'allowed_ui_conf_hosts' => CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Inventory explorer'));
		$this->setResponse($response);
	}
}
