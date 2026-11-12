<?php declare(strict_types = 0);
/*
** Inventory Plus - Analytics page.
**/

namespace Modules\InventoryPlus\Actions;

use CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CProfile,
	CRoleHelper,
	CWebUser;

use Modules\InventoryPlus\Includes\InventoryFields,
	Modules\InventoryPlus\Includes\InventoryQuery;

class Analytics extends CController {

	private const PROFILE = 'web.inventoryplus.analytics';
	private const TOP_OPTIONS = [10, 20, 50, 0];
	private const MAX_CROSSTAB_COLUMNS = 12;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'filter_groupids' =>	'array_id',
			'filter_status' =>		'in -1,'.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED,
			'filter_evaltype' =>	'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'filter_tags' =>		'array',
			'view_set' =>			'in 1',
			'field' =>				'string',
			'field2' =>				'string',
			'normalize' =>			'in 0,1',
			'show_empty' =>			'in 0,1',
			'top' =>				'in '.implode(',', self::TOP_OPTIONS)
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_OVERVIEW);
	}

	protected function doAction(): void {
		$p = self::PROFILE;

		// Filter (scope).
		if ($this->hasInput('filter_set')) {
			CProfile::updateArray($p.'.filter.groupids', $this->getInput('filter_groupids', []), PROFILE_TYPE_ID);
			CProfile::update($p.'.filter.status', $this->getInput('filter_status', -1), PROFILE_TYPE_INT);
			CProfile::update($p.'.filter.evaltype', $this->getInput('filter_evaltype', TAG_EVAL_TYPE_AND_OR),
				PROFILE_TYPE_INT
			);

			$tags = InventoryQuery::sanitizeTags($this->getInput('filter_tags', []));
			CProfile::updateArray($p.'.filter.tags.tag', array_column($tags, 'tag'), PROFILE_TYPE_STR);
			CProfile::updateArray($p.'.filter.tags.value', array_column($tags, 'value'), PROFILE_TYPE_STR);
			CProfile::updateArray($p.'.filter.tags.operator', array_column($tags, 'operator'), PROFILE_TYPE_INT);
		}
		elseif ($this->hasInput('filter_rst')) {
			foreach (['groupids', 'status', 'evaltype', 'tags.tag', 'tags.value', 'tags.operator'] as $key) {
				CProfile::deleteIdx($p.'.filter.'.$key);
			}
		}

		// View options (what to analyze).
		foreach (['field' => PROFILE_TYPE_STR, 'field2' => PROFILE_TYPE_STR, 'normalize' => PROFILE_TYPE_INT,
				'show_empty' => PROFILE_TYPE_INT, 'top' => PROFILE_TYPE_INT] as $key => $type) {
			if ($this->hasInput($key)) {
				CProfile::update($p.'.'.$key, $this->getInput($key), $type);
			}
		}

		$filter = [
			'groupids' => CProfile::getArray($p.'.filter.groupids', []),
			'status' => (int) CProfile::get($p.'.filter.status', -1),
			'evaltype' => (int) CProfile::get($p.'.filter.evaltype', TAG_EVAL_TYPE_AND_OR),
			'tags' => []
		];

		foreach (CProfile::getArray($p.'.filter.tags.tag', []) as $i => $tag) {
			$filter['tags'][] = [
				'tag' => $tag,
				'value' => CProfile::get($p.'.filter.tags.value', '', $i),
				'operator' => (int) CProfile::get($p.'.filter.tags.operator', TAG_OPERATOR_LIKE, $i)
			];
		}

		$options = [
			'field' => CProfile::get($p.'.field', 'type'),
			'field2' => CProfile::get($p.'.field2', ''),
			'normalize' => (int) CProfile::get($p.'.normalize', 1),
			'show_empty' => (int) CProfile::get($p.'.show_empty', 1),
			'top' => (int) CProfile::get($p.'.top', 20)
		];

		if (!InventoryFields::isField($options['field'])) {
			$options['field'] = 'type';
		}

		if ($options['field2'] !== '' && (!InventoryFields::isField($options['field2'])
				|| $options['field2'] === $options['field'])) {
			$options['field2'] = '';
		}

		if (!in_array($options['top'], self::TOP_OPTIONS)) {
			$options['top'] = 20;
		}

		$ms_groups = [];
		$groupids = $filter['groupids'] ? getSubGroups($filter['groupids'], $ms_groups) : null;

		$hosts = InventoryQuery::getHosts([
			'groupids' => $groupids,
			'status' => $filter['status'],
			'evaltype' => $filter['evaltype'],
			'tags' => InventoryQuery::sanitizeTags($filter['tags'])
		], InventoryFields::getFieldNames());

		$enabled_hosts = array_filter($hosts, static function ($host) {
			return $host['inventory_mode'] != HOST_INVENTORY_DISABLED;
		});

		$field_names = InventoryFields::getFieldNames();
		$field_count = count($field_names);
		$enabled_count = count($enabled_hosts);

		// Fill rate per field and overall completeness.
		$fill = array_fill_keys($field_names, 0);
		$filled_total = 0;

		foreach ($enabled_hosts as $host) {
			foreach ($field_names as $field) {
				if (trim($host['inventory'][$field]) !== '') {
					$fill[$field]++;
					$filled_total++;
				}
			}
		}

		$distribution = self::distribution($enabled_hosts, $options['field'], (bool) $options['normalize']);

		$data = [
			'filter' => $filter,
			'ms_groups' => $ms_groups,
			'options' => $options,
			'profileIdx' => $p.'.filter',
			'active_tab' => CProfile::get($p.'.filter.active', 1),
			'fields_by_section' => InventoryFields::getFieldsBySection(),
			'sections' => InventoryFields::getSections(),
			'top_options' => self::TOP_OPTIONS,
			'kpi' => [
				'hosts' => count($hosts),
				'enabled' => $enabled_count,
				'disabled' => count($hosts) - $enabled_count,
				'avg_completeness' => $enabled_count > 0
					? (int) round($filled_total / ($enabled_count * $field_count) * 100)
					: 0,
				'distinct' => count($distribution['groups']),
				'not_set' => $distribution['empty'],
				'variant_groups' => count(array_filter($distribution['groups'], static function ($group) {
					return count($group['variants']) > 1;
				}))
			],
			'distribution' => $distribution,
			'crosstab' => $options['field2'] !== ''
				? self::crosstab($enabled_hosts, $options['field'], $options['field2'], (bool) $options['normalize'],
					$options['top'] ?: 50, (bool) $options['show_empty']
				)
				: null,
			'fill' => $fill,
			'field_count' => $field_count,
			'allowed_ui_inventory_hosts' => CWebUser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Inventory analytics'));
		$this->setResponse($response);
	}

	/**
	 * Group hosts by the value of one field.
	 *
	 * @return array
	 *   'groups' => key => ['label' => string, 'count' => int, 'variants' => [value => count]] sorted by count desc,
	 *   'empty'  => number of hosts with an empty value.
	 */
	private static function distribution(array $hosts, string $field, bool $normalize): array {
		$groups = [];
		$empty = 0;

		foreach ($hosts as $host) {
			$value = $host['inventory'][$field];

			if (trim($value) === '') {
				$empty++;
				continue;
			}

			$key = $normalize ? InventoryQuery::normalize($value) : $value;

			if (!array_key_exists($key, $groups)) {
				$groups[$key] = ['label' => $value, 'count' => 0, 'variants' => []];
			}

			$groups[$key]['count']++;
			$groups[$key]['variants'][$value] = array_key_exists($value, $groups[$key]['variants'])
				? $groups[$key]['variants'][$value] + 1
				: 1;
		}

		foreach ($groups as &$group) {
			// Label the group with its most common spelling.
			arsort($group['variants']);
			$group['label'] = trim((string) array_key_first($group['variants']));
		}
		unset($group);

		uasort($groups, static function ($a, $b) {
			return $b['count'] <=> $a['count'] ?: strnatcasecmp($a['label'], $b['label']);
		});

		return ['groups' => $groups, 'empty' => $empty];
	}

	/**
	 * Two-field cross tabulation.
	 *
	 * @return array
	 *   'rows'    => key => label (top $limit values of $field),
	 *   'columns' => key => label (top values of $field2, '' = not set),
	 *   'cells'   => row key => column key => count,
	 *   'max'     => largest cell value.
	 */
	private static function crosstab(array $hosts, string $field, string $field2, bool $normalize, int $limit,
			bool $show_empty): array {
		$rows = self::distribution($hosts, $field, $normalize);
		$columns = self::distribution($hosts, $field2, $normalize);

		$row_labels = array_map(static function ($group) {
			return $group['label'];
		}, array_slice($rows['groups'], 0, $limit, true));

		$column_labels = array_map(static function ($group) {
			return $group['label'];
		}, array_slice($columns['groups'], 0, self::MAX_CROSSTAB_COLUMNS, true));

		if ($show_empty && $rows['empty'] > 0) {
			$row_labels[''] = '';
		}

		if ($show_empty && $columns['empty'] > 0) {
			$column_labels[''] = '';
		}

		$cells = [];
		$max = 0;
		$other_columns = false;

		foreach ($hosts as $host) {
			$v1 = trim($host['inventory'][$field]);
			$v2 = trim($host['inventory'][$field2]);
			$k1 = $v1 === '' ? '' : ($normalize ? InventoryQuery::normalize($v1) : $host['inventory'][$field]);
			$k2 = $v2 === '' ? '' : ($normalize ? InventoryQuery::normalize($v2) : $host['inventory'][$field2]);

			if (!array_key_exists($k1, $row_labels)) {
				continue;
			}

			if (!array_key_exists($k2, $column_labels)) {
				$k2 = "\0other";
				$other_columns = true;
			}

			$cells[$k1][$k2] = (array_key_exists($k1, $cells) && array_key_exists($k2, $cells[$k1]))
				? $cells[$k1][$k2] + 1
				: 1;
			$max = max($max, $cells[$k1][$k2]);
		}

		if ($other_columns) {
			$column_labels["\0other"] = "\0other";
		}

		return [
			'rows' => $row_labels,
			'columns' => $column_labels,
			'cells' => $cells,
			'max' => $max,
			'more_rows' => max(0, count($rows['groups']) - $limit)
		];
	}
}
