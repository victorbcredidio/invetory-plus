<?php declare(strict_types = 0);
/*
** Inventory Plus - Explorer filter persistence and dataset building (shared by the page and the export).
**/

namespace Modules\InventoryPlus\Includes;

use APP,
	CProfile;

class ExplorerData {

	public const PROFILE = 'web.inventoryplus.explorer';

	public const DEFAULT_COLUMNS = ['type', 'vendor', 'model', 'os', 'serialno_a', 'site_city',
		InventoryFields::COL_ADDED_ON, InventoryFields::COL_COMPLETENESS
	];

	/**
	 * Read a module configuration option (manifest "config" section), with a fallback.
	 */
	public static function getOption(string $name, $default) {
		$module = APP::ModuleManager()->getActionModule();

		return $module !== null ? $module->getOption($name, $default) : $default;
	}

	/**
	 * Store the filter submitted by the user.
	 */
	public static function updateFilter(array $input): void {
		$p = self::PROFILE.'.filter.';

		CProfile::updateArray($p.'groupids', array_key_exists('filter_groupids', $input) ? $input['filter_groupids'] : [],
			PROFILE_TYPE_ID
		);
		CProfile::update($p.'name', array_key_exists('filter_name', $input) ? $input['filter_name'] : '',
			PROFILE_TYPE_STR
		);
		CProfile::update($p.'status', array_key_exists('filter_status', $input) ? $input['filter_status'] : -1,
			PROFILE_TYPE_INT
		);
		CProfile::update($p.'inventory_mode',
			array_key_exists('filter_inventory_mode', $input) ? $input['filter_inventory_mode'] : InventoryQuery::MODE_ANY,
			PROFILE_TYPE_STR
		);
		CProfile::update($p.'evaltype',
			array_key_exists('filter_evaltype', $input) ? $input['filter_evaltype'] : TAG_EVAL_TYPE_AND_OR,
			PROFILE_TYPE_INT
		);
		CProfile::update($p.'cond_evaltype',
			array_key_exists('filter_cond_evaltype', $input) ? $input['filter_cond_evaltype'] : InventoryQuery::EVAL_AND,
			PROFILE_TYPE_INT
		);

		$tags = InventoryQuery::sanitizeTags(array_key_exists('filter_tags', $input) ? $input['filter_tags'] : []);
		CProfile::updateArray($p.'tags.tag', array_column($tags, 'tag'), PROFILE_TYPE_STR);
		CProfile::updateArray($p.'tags.value', array_column($tags, 'value'), PROFILE_TYPE_STR);
		CProfile::updateArray($p.'tags.operator', array_column($tags, 'operator'), PROFILE_TYPE_INT);

		$conditions = InventoryQuery::sanitizeConditions(
			array_key_exists('filter_conditions', $input) ? $input['filter_conditions'] : []
		);
		CProfile::updateArray($p.'cond.field', array_column($conditions, 'field'), PROFILE_TYPE_STR);
		CProfile::updateArray($p.'cond.operator', array_column($conditions, 'operator'), PROFILE_TYPE_STR);
		CProfile::updateArray($p.'cond.value', array_column($conditions, 'value'), PROFILE_TYPE_STR);
	}

	public static function resetFilter(): void {
		$p = self::PROFILE.'.filter.';

		foreach (['groupids', 'name', 'status', 'inventory_mode', 'evaltype', 'cond_evaltype', 'tags.tag', 'tags.value',
				'tags.operator', 'cond.field', 'cond.operator', 'cond.value'] as $key) {
			CProfile::deleteIdx($p.$key);
		}
	}

	public static function getFilter(): array {
		$p = self::PROFILE.'.filter.';

		$filter = [
			'groupids' => CProfile::getArray($p.'groupids', []),
			'name' => CProfile::get($p.'name', ''),
			'status' => (int) CProfile::get($p.'status', -1),
			'inventory_mode' => CProfile::get($p.'inventory_mode', InventoryQuery::MODE_ANY),
			'evaltype' => (int) CProfile::get($p.'evaltype', TAG_EVAL_TYPE_AND_OR),
			'cond_evaltype' => (int) CProfile::get($p.'cond_evaltype', InventoryQuery::EVAL_AND),
			'tags' => [],
			'conditions' => []
		];

		foreach (CProfile::getArray($p.'tags.tag', []) as $i => $tag) {
			$filter['tags'][] = [
				'tag' => $tag,
				'value' => CProfile::get($p.'tags.value', '', $i),
				'operator' => (int) CProfile::get($p.'tags.operator', TAG_OPERATOR_LIKE, $i)
			];
		}

		foreach (CProfile::getArray($p.'cond.field', []) as $i => $field) {
			$filter['conditions'][] = [
				'field' => $field,
				'operator' => CProfile::get($p.'cond.operator', InventoryQuery::OP_CONTAINS, $i),
				'value' => CProfile::get($p.'cond.value', '', $i)
			];
		}

		$filter['conditions'] = InventoryQuery::sanitizeConditions($filter['conditions']);

		if (!array_key_exists($filter['inventory_mode'], InventoryQuery::getInventoryModeOptions())) {
			$filter['inventory_mode'] = InventoryQuery::MODE_ANY;
		}

		return $filter;
	}

	public static function isFilterActive(array $filter): bool {
		return $filter['groupids'] || $filter['name'] !== '' || $filter['status'] != -1
			|| $filter['inventory_mode'] !== InventoryQuery::MODE_ANY || $filter['tags'] || $filter['conditions'];
	}

	/**
	 * Marker stored when the user deliberately hides every optional column (only "Host" remains).
	 * Without it an empty selection would fall back to the default columns.
	 */
	private const COLUMNS_NONE = '_none';

	/**
	 * Visible columns in the user's chosen order.
	 */
	public static function getColumns(): array {
		$default = self::getOption('default_columns', self::DEFAULT_COLUMNS);
		$columns = CProfile::getArray(self::PROFILE.'.columns', null);

		if ($columns === null) {
			$columns = is_array($default) ? $default : self::DEFAULT_COLUMNS;
		}
		else {
			// Profile rows are keyed by position (idx2); make the order explicit.
			ksort($columns);
		}

		return array_values(array_unique(array_filter($columns, static function ($column) {
			return is_string($column) && InventoryFields::isColumn($column);
		})));
	}

	public static function setColumns(array $columns): void {
		$columns = array_values(array_unique(array_filter($columns, static function ($column) {
			return is_string($column) && InventoryFields::isColumn($column);
		})));

		CProfile::updateArray(self::PROFILE.'.columns', $columns ?: [self::COLUMNS_NONE], PROFILE_TYPE_STR);
	}

	public static function resetColumns(): void {
		CProfile::deleteIdx(self::PROFILE.'.columns');
	}

	/**
	 * Build the full (unpaged) dataset for the given filter and columns.
	 *
	 * @return array
	 *   'hosts'            => hostid => host (sorted),
	 *   'added_on_allowed' => bool,
	 *   'oldest_audit'     => int|null,
	 *   'ms_groups'        => multiselect data for selected groups
	 */
	public static function build(array $filter, array $columns, string $sort, string $sortorder): array {
		$ms_groups = [];
		$groupids = $filter['groupids'] ? getSubGroups($filter['groupids'], $ms_groups) : null;

		$need = array_merge($columns, [$sort]);
		$need_all_fields = in_array(InventoryFields::COL_COMPLETENESS, $need);

		$fields = $need_all_fields
			? InventoryFields::getFieldNames()
			: array_values(array_filter($need, [InventoryFields::class, 'isField']));

		$hosts = InventoryQuery::getHosts(['groupids' => $groupids] + $filter, $fields, [
			'groups' => in_array(InventoryFields::COL_GROUPS, $columns),
			'tags' => in_array(InventoryFields::COL_TAGS, $columns)
		]);

		$added_on_allowed = AddedOn::isAllowed((string) self::getOption('added_on_visible_to', 'all'));
		$oldest_audit = null;

		if ($added_on_allowed && in_array(InventoryFields::COL_ADDED_ON, $need)) {
			$added_on = AddedOn::getByHostIds(array_keys($hosts));
			$oldest_audit = AddedOn::getOldestRecordClock();

			foreach ($hosts as $hostid => &$host) {
				$host['added_on'] = array_key_exists($hostid, $added_on) ? $added_on[$hostid] : null;
			}
			unset($host);
		}

		if ($need_all_fields) {
			foreach ($hosts as &$host) {
				$host['filled'] = InventoryQuery::countFilled($host['inventory']);
			}
			unset($host);
		}

		if ($sort === InventoryFields::COL_ADDED_ON && !$added_on_allowed) {
			$sort = 'name';
		}

		InventoryQuery::sort($hosts, $sort, $sortorder);

		return [
			'hosts' => $hosts,
			'added_on_allowed' => $added_on_allowed,
			'oldest_audit' => $oldest_audit,
			'ms_groups' => $ms_groups
		];
	}
}
