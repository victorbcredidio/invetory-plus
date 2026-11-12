<?php declare(strict_types = 0);
/*
** Inventory Plus - host loading, filtering, sorting.
**/

namespace Modules\InventoryPlus\Includes;

use API;

/**
 * All host data is read through the frontend API (API::Host()->get()), so user permissions are always respected.
 * Inventory conditions are evaluated in PHP to support operators the API does not offer (empty / not empty /
 * not contains / normalized equality) with AND/OR logic.
 */
class InventoryQuery {

	public const OP_EQUALS = 'equals';
	public const OP_NOT_EQUALS = 'not_equals';
	public const OP_CONTAINS = 'contains';
	public const OP_NOT_CONTAINS = 'not_contains';
	public const OP_EMPTY = 'empty';
	public const OP_NOT_EMPTY = 'not_empty';

	public const EVAL_AND = 0;
	public const EVAL_OR = 1;

	public const MODE_ANY = 'any';
	public const MODE_ENABLED = 'enabled';
	public const MODE_MANUAL = 'manual';
	public const MODE_AUTOMATIC = 'automatic';
	public const MODE_DISABLED = 'disabled';

	public static function getOperators(): array {
		return [
			self::OP_CONTAINS => _('contains'),
			self::OP_EQUALS => _('equals'),
			self::OP_NOT_CONTAINS => _('does not contain'),
			self::OP_NOT_EQUALS => _('does not equal'),
			self::OP_EMPTY => _('is empty'),
			self::OP_NOT_EMPTY => _('is not empty')
		];
	}

	public static function getInventoryModeOptions(): array {
		return [
			self::MODE_ANY => _('Any'),
			self::MODE_ENABLED => _('Manual or automatic'),
			self::MODE_MANUAL => _('Manual'),
			self::MODE_AUTOMATIC => _('Automatic'),
			self::MODE_DISABLED => _('Disabled')
		];
	}

	/**
	 * Normalize a value for comparison and grouping: trim, collapse whitespace, case-insensitive.
	 */
	public static function normalize(string $value): string {
		return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
	}

	/**
	 * Clean user-supplied inventory conditions: drop invalid rows, drop rows without value for value operators.
	 *
	 * @param array $conditions  [['field' => string, 'operator' => string, 'value' => string], ...]
	 */
	public static function sanitizeConditions(array $conditions): array {
		$result = [];
		$operators = self::getOperators();

		foreach ($conditions as $condition) {
			if (!is_array($condition)) {
				continue;
			}

			$field = array_key_exists('field', $condition) ? (string) $condition['field'] : '';
			$operator = array_key_exists('operator', $condition) ? (string) $condition['operator'] : '';
			$value = array_key_exists('value', $condition) ? (string) $condition['value'] : '';

			if (!InventoryFields::isField($field) || !array_key_exists($operator, $operators)) {
				continue;
			}

			if ($value === '' && !in_array($operator, [self::OP_EMPTY, self::OP_NOT_EMPTY])) {
				continue;
			}

			$result[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
		}

		return $result;
	}

	/**
	 * Clean tag filter rows coming from CTagFilterFieldHelper fields.
	 */
	public static function sanitizeTags(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			if (!is_array($tag) || !array_key_exists('tag', $tag)) {
				continue;
			}

			$tag += ['value' => '', 'operator' => TAG_OPERATOR_LIKE];

			if ($tag['tag'] === '' && $tag['value'] === '') {
				continue;
			}

			$result[] = [
				'tag' => (string) $tag['tag'],
				'value' => (string) $tag['value'],
				'operator' => (int) $tag['operator']
			];
		}

		return $result;
	}

	/**
	 * Load hosts matching the filter.
	 *
	 * @param array  $filter
	 * @param array  $filter['groupids']        Host group IDs, already expanded with subgroups, or null.
	 * @param string $filter['name']            Host name substring.
	 * @param int    $filter['status']          -1 any, 0 enabled, 1 disabled.
	 * @param string $filter['inventory_mode']  One of self::MODE_*.
	 * @param int    $filter['evaltype']        Tag evaluation type.
	 * @param array  $filter['tags']            Sanitized tag conditions.
	 * @param array  $filter['conditions']      Sanitized inventory conditions.
	 * @param int    $filter['cond_evaltype']   self::EVAL_AND or self::EVAL_OR.
	 * @param array  $fields                    Inventory fields to load (condition fields are added automatically).
	 * @param array  $select                    Extra selects: 'groups' => bool, 'tags' => bool.
	 *
	 * @return array  hostid => host; host['inventory'] always contains every requested field ('' when empty).
	 */
	public static function getHosts(array $filter, array $fields, array $select = []): array {
		$filter += [
			'groupids' => null,
			'name' => '',
			'status' => -1,
			'inventory_mode' => self::MODE_ANY,
			'evaltype' => TAG_EVAL_TYPE_AND_OR,
			'tags' => [],
			'conditions' => [],
			'cond_evaltype' => self::EVAL_AND
		];

		foreach ($filter['conditions'] as $condition) {
			$fields[] = $condition['field'];
		}

		$fields = array_values(array_unique(array_filter($fields, [InventoryFields::class, 'isField'])));

		$api_filter = [];

		if ($filter['status'] != -1) {
			$api_filter['status'] = (int) $filter['status'];
		}

		$modes = [
			self::MODE_ENABLED => [HOST_INVENTORY_MANUAL, HOST_INVENTORY_AUTOMATIC],
			self::MODE_MANUAL => [HOST_INVENTORY_MANUAL],
			self::MODE_AUTOMATIC => [HOST_INVENTORY_AUTOMATIC],
			self::MODE_DISABLED => [HOST_INVENTORY_DISABLED]
		];

		if (array_key_exists($filter['inventory_mode'], $modes)) {
			$api_filter['inventory_mode'] = $modes[$filter['inventory_mode']];
		}

		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'inventory_mode'],
			'selectInventory' => $fields ?: ['type'],
			'groupids' => $filter['groupids'] ?: null,
			'filter' => $api_filter ?: null,
			'search' => $filter['name'] !== '' ? ['name' => $filter['name']] : null,
			'preservekeys' => true
		];

		if ($filter['tags']) {
			$options['evaltype'] = (int) $filter['evaltype'];
			$options['tags'] = $filter['tags'];
		}

		if (!empty($select['groups'])) {
			$options['selectHostGroups'] = ['groupid', 'name'];
		}

		if (!empty($select['tags'])) {
			$options['selectTags'] = ['tag', 'value'];
		}

		$hosts = API::Host()->get($options);

		$empty_inventory = array_fill_keys($fields, '');

		foreach ($hosts as $hostid => &$host) {
			$inventory = is_array($host['inventory']) ? $host['inventory'] : [];
			unset($inventory['hostid']);
			$host['inventory'] = array_intersect_key($inventory + $empty_inventory, $empty_inventory);

			if ($filter['conditions']
					&& !self::matchesConditions($host['inventory'], $filter['conditions'], $filter['cond_evaltype'])) {
				unset($hosts[$hostid]);
			}
		}
		unset($host);

		return $hosts;
	}

	public static function matchesConditions(array $inventory, array $conditions, int $evaltype): bool {
		foreach ($conditions as $condition) {
			$match = self::matchesCondition(
				array_key_exists($condition['field'], $inventory) ? (string) $inventory[$condition['field']] : '',
				$condition['operator'],
				$condition['value']
			);

			if ($evaltype == self::EVAL_OR && $match) {
				return true;
			}

			if ($evaltype == self::EVAL_AND && !$match) {
				return false;
			}
		}

		return $evaltype == self::EVAL_AND;
	}

	public static function matchesCondition(string $haystack, string $operator, string $needle): bool {
		$haystack_n = self::normalize($haystack);
		$needle_n = self::normalize($needle);

		switch ($operator) {
			case self::OP_EQUALS:
				return $haystack_n === $needle_n;

			case self::OP_NOT_EQUALS:
				return $haystack_n !== $needle_n;

			case self::OP_CONTAINS:
				return mb_strpos($haystack_n, $needle_n) !== false;

			case self::OP_NOT_CONTAINS:
				return mb_strpos($haystack_n, $needle_n) === false;

			case self::OP_EMPTY:
				return trim($haystack) === '';

			case self::OP_NOT_EMPTY:
				return trim($haystack) !== '';
		}

		return false;
	}

	/**
	 * Number of non-empty inventory fields.
	 */
	public static function countFilled(array $inventory): int {
		$count = 0;

		foreach ($inventory as $field => $value) {
			if ($field !== 'hostid' && trim((string) $value) !== '') {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Sort hosts by a column. Empty values always go last, regardless of direction. Ties are broken by host name.
	 *
	 * @param array  $hosts       Hosts as returned by getHosts(), optionally with 'added_on' and 'filled' keys.
	 * @param string $column      'name', an inventory field or a virtual column.
	 * @param string $sortorder   ZBX_SORT_UP or ZBX_SORT_DOWN.
	 */
	public static function sort(array &$hosts, string $column, string $sortorder): void {
		$keys = [];

		foreach ($hosts as $hostid => $host) {
			switch ($column) {
				case 'name':
					$value = $host['name'];
					break;

				case InventoryFields::COL_STATUS:
					$value = (int) $host['status'];
					break;

				case InventoryFields::COL_INVENTORY_MODE:
					$value = (int) $host['inventory_mode'];
					break;

				case InventoryFields::COL_ADDED_ON:
					// Unknown creation time: order by host ID (IDs grow over time), before all known dates.
					$value = array_key_exists('added_on', $host) && $host['added_on'] !== null
						? (int) $host['added_on']['clock']
						: null;
					break;

				case InventoryFields::COL_COMPLETENESS:
					$value = array_key_exists('filled', $host) ? (int) $host['filled'] : 0;
					break;

				default:
					$value = array_key_exists($column, $host['inventory']) ? trim($host['inventory'][$column]) : '';
					$value = $value === '' ? null : $value;
			}

			$keys[$hostid] = $value;
		}

		$direction = $sortorder === ZBX_SORT_DOWN ? -1 : 1;

		uksort($hosts, static function ($a, $b) use ($keys, $hosts, $column, $direction) {
			$va = $keys[$a];
			$vb = $keys[$b];

			if ($column === InventoryFields::COL_ADDED_ON && ($va === null || $vb === null)) {
				if ($va === null && $vb === null) {
					return ((int) $a <=> (int) $b) * $direction;
				}

				// Unknown = older than any known date.
				return ($va === null ? -1 : 1) * $direction;
			}

			if ($va === null || $vb === null) {
				if ($va === null && $vb === null) {
					return strnatcasecmp($hosts[$a]['name'], $hosts[$b]['name']);
				}

				return $va === null ? 1 : -1;
			}

			$cmp = (is_int($va) && is_int($vb)) ? $va <=> $vb : strnatcasecmp((string) $va, (string) $vb);

			if ($cmp == 0) {
				return strnatcasecmp($hosts[$a]['name'], $hosts[$b]['name']);
			}

			return $cmp * $direction;
		});
	}
}
