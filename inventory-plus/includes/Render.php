<?php declare(strict_types = 0);
/*
** Inventory Plus - shared rendering helpers for views.
**/

namespace Modules\InventoryPlus\Includes;

use CDiv,
	CLink,
	CSelect,
	CSelectOption,
	CSelectOptionGroup,
	CSpan,
	CUrl;

class Render {

	/**
	 * Fill a CSelect with inventory fields grouped by section.
	 * Note: option groups must be added with addOptionGroup(); addOptions() accepts plain options only.
	 */
	public static function addFieldOptions(CSelect $select, bool $with_none = false): CSelect {
		if ($with_none) {
			$select->addOption(new CSelectOption('', _('None')));
		}

		$sections = InventoryFields::getSections();

		foreach (InventoryFields::getFieldsBySection() as $section => $fields) {
			if ($fields) {
				$select->addOptionGroup(
					(new CSelectOptionGroup($sections[$section]))->addOptions(CSelect::createOptionsFromArray($fields))
				);
			}
		}

		return $select;
	}

	/**
	 * Render an inventory value. Only http(s), mailto: and tel: links are produced; everything else is plain text.
	 *
	 * @return CLink|CSpan|string
	 */
	public static function value(string $field, string $value, bool $truncate = false) {
		$value = trim($value);

		if ($value === '') {
			return '';
		}

		if (preg_match('/^url_[a-c]$/', $field) && preg_match('#^https?://#i', $value)) {
			return (new CLink($value, $value))
				->setTarget('_blank')
				->addClass('ip-ellipsis')
				->setTitle($value);
		}

		if (preg_match('/_email$/', $field) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
			return new CLink($value, 'mailto:'.$value);
		}

		if (preg_match('/_(phone_a|phone_b|cell)$/', $field)) {
			$tel = preg_replace('/[^0-9+]/', '', $value);

			if ($tel !== '') {
				return new CLink($value, 'tel:'.$tel);
			}
		}

		$span = new CSpan($value);

		if ($truncate) {
			$span->addClass('ip-ellipsis')->setTitle($value);
		}
		else {
			$span->addClass('ip-pre-wrap');
		}

		return $span;
	}

	public static function status(int $status): CSpan {
		return $status == HOST_STATUS_MONITORED
			? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED);
	}

	public static function inventoryMode(int $mode): CSpan {
		$modes = InventoryFields::getInventoryModes();
		$classes = [
			HOST_INVENTORY_DISABLED => 'ip-badge-muted',
			HOST_INVENTORY_MANUAL => 'ip-badge-manual',
			HOST_INVENTORY_AUTOMATIC => 'ip-badge-auto'
		];

		return (new CSpan(array_key_exists($mode, $modes) ? $modes[$mode] : '?'))
			->addClass('ip-badge')
			->addClass(array_key_exists($mode, $classes) ? $classes[$mode] : '');
	}

	/**
	 * @param array|null $added_on      ['clock' => int, 'username' => string] or null.
	 * @param int|null   $oldest_audit  Oldest audit log record timestamp.
	 * @param bool       $show_user     Whether to reveal the creating user.
	 */
	public static function addedOn(?array $added_on, ?int $oldest_audit, bool $show_user): CSpan {
		if ($added_on !== null) {
			$title = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $added_on['clock']);

			if ($show_user && $added_on['username'] !== '') {
				$title .= ' — '._s('by %1$s', $added_on['username']);
			}

			return (new CSpan(zbx_date2str(DATE_FORMAT, $added_on['clock'])))
				->addClass(ZBX_STYLE_NOWRAP)
				->setTitle($title);
		}

		if ($oldest_audit !== null) {
			return (new CSpan(_s('before %1$s', zbx_date2str(DATE_FORMAT, $oldest_audit))))
				->addClass(ZBX_STYLE_GREY)
				->addClass(ZBX_STYLE_NOWRAP)
				->setTitle(_('No creation record in the audit log. The host was created before the oldest audit log record, or while audit logging was disabled.'));
		}

		return (new CSpan(_('Unknown')))
			->addClass(ZBX_STYLE_GREY)
			->setTitle(_('The audit log is empty or disabled.'));
	}

	/**
	 * Small horizontal meter with a percentage.
	 */
	public static function meter(int $filled, int $total, bool $with_label = true): CDiv {
		$pct = $total > 0 ? (int) round($filled / $total * 100) : 0;

		$level = $pct >= 60 ? 'ip-meter-good' : ($pct >= 30 ? 'ip-meter-fair' : 'ip-meter-poor');

		return (new CDiv([
			(new CDiv(
				(new CDiv())
					->addClass('ip-meter-fill')
					->addClass($level)
					->addStyle('width: '.$pct.'%;')
			))->addClass('ip-meter-track'),
			$with_label ? (new CSpan($pct.'%'))->addClass('ip-meter-label') : null
		]))
			->addClass('ip-meter')
			->setTitle(_s('%1$s of %2$s fields filled', $filled, $total));
	}

	public static function tags(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			$label = $tag['value'] === '' ? $tag['tag'] : $tag['tag'].': '.$tag['value'];
			$result[] = (new CSpan($label))
				->addClass(ZBX_STYLE_TAG)
				->setTitle($label);
		}

		return $result;
	}

	public static function hostCardUrl(string $hostid): string {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'inventoryplus.host')
			->setArgument('hostid', $hostid)
			->getUrl();
	}

	/**
	 * URL to the Explorer with a fresh filter (overwrites the user's saved Explorer filter, like built-in drill-downs).
	 *
	 * @param array $groupids
	 * @param array $conditions  [['field' => ..., 'operator' => ..., 'value' => ...], ...]
	 * @param array $extra       Additional filter_* arguments.
	 */
	public static function explorerUrl(array $groupids, array $conditions, array $extra = []): string {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'inventoryplus.explorer')
			->setArgument('filter_set', '1');

		if ($groupids) {
			$url->setArgument('filter_groupids', array_values($groupids));
		}

		if ($conditions) {
			$url->setArgument('filter_conditions', array_values($conditions));
		}

		foreach ($extra as $key => $value) {
			$url->setArgument($key, $value);
		}

		return $url->getUrl();
	}
}
