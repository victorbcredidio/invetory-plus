<?php declare(strict_types = 0);
/*
** Inventory Plus - "Added on" resolver.
**/

namespace Modules\InventoryPlus\Includes;

use CAudit,
	CWebUser;

/**
 * Resolves when hosts were created, using the audit log ("Add" actions on the "Host" resource).
 *
 * Zabbix has no host creation timestamp column, so this is the only source available without schema changes.
 * Limitations:
 *   - data exists only for the audit log retention period (Administration > Audit log);
 *   - nothing is recorded while audit logging is disabled.
 * For hosts without an audit record the caller can show "before <oldest audit record>".
 *
 * The audit log is read directly (read-only SELECT) because the auditlog.get API is restricted to Super admins.
 * Callers must only pass host IDs that the current user is already permitted to read (i.e. returned by the
 * Host API), so no information about inaccessible hosts is exposed.
 */
class AddedOn {

	private static $oldest_record = false;

	/**
	 * Whether "Added on" data may be shown to the current user, according to module configuration.
	 *
	 * @param string $visible_to  "all" or "super_admin"
	 */
	public static function isAllowed(string $visible_to): bool {
		return $visible_to !== 'super_admin' || CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	/**
	 * @param array $hostids
	 *
	 * @return array  hostid => ['clock' => int, 'username' => string]
	 */
	public static function getByHostIds(array $hostids): array {
		$result = [];

		if (!$hostids) {
			return $result;
		}

		foreach (array_chunk(array_values($hostids), 1000) as $chunk) {
			$db_rows = DBselect(
				'SELECT a.resourceid,a.clock,a.username'.
				' FROM auditlog a'.
				' WHERE a.resourcetype='.CAudit::RESOURCE_HOST.
					' AND a.action='.CAudit::ACTION_ADD.
					' AND '.dbConditionId('a.resourceid', $chunk)
			);

			while ($row = DBfetch($db_rows)) {
				$hostid = $row['resourceid'];

				// Keep the earliest record if a host ID appears more than once.
				if (!array_key_exists($hostid, $result) || $row['clock'] < $result[$hostid]['clock']) {
					$result[$hostid] = [
						'clock' => (int) $row['clock'],
						'username' => $row['username']
					];
				}
			}
		}

		return $result;
	}

	/**
	 * Timestamp of the oldest audit log record, or null if the audit log is empty.
	 */
	public static function getOldestRecordClock(): ?int {
		if (self::$oldest_record === false) {
			$row = DBfetch(DBselect('SELECT MIN(clock) AS clock FROM auditlog'));
			self::$oldest_record = ($row && $row['clock'] !== null) ? (int) $row['clock'] : null;
		}

		return self::$oldest_record;
	}
}
