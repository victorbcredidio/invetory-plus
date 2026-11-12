<?php declare(strict_types = 0);
/*
** Inventory Plus - field catalogue.
**/

namespace Modules\InventoryPlus\Includes;

/**
 * Inventory field metadata. Field names and titles come from Zabbix itself (getHostInventories()), so they stay
 * translated and in sync with the running version. This class only adds grouping into sections and
 * a few "virtual" columns computed by the module.
 */
class InventoryFields {

	public const SECTION_IDENTITY = 'identity';
	public const SECTION_HARDWARE = 'hardware';
	public const SECTION_SOFTWARE = 'software';
	public const SECTION_NETWORK = 'network';
	public const SECTION_LOCATION = 'location';
	public const SECTION_LIFECYCLE = 'lifecycle';
	public const SECTION_CONTACTS = 'contacts';
	public const SECTION_NOTES = 'notes';

	/** Virtual (non-inventory) columns. */
	public const COL_GROUPS = '_groups';
	public const COL_STATUS = '_status';
	public const COL_INVENTORY_MODE = '_inventory_mode';
	public const COL_ADDED_ON = '_added_on';
	public const COL_COMPLETENESS = '_completeness';
	public const COL_TAGS = '_tags';

	private const SECTION_MAP = [
		self::SECTION_IDENTITY => ['type', 'type_full', 'name', 'alias', 'tag', 'asset_tag', 'serialno_a', 'serialno_b',
			'deployment_status'
		],
		self::SECTION_HARDWARE => ['vendor', 'model', 'chassis', 'hw_arch', 'hardware', 'hardware_full', 'macaddress_a',
			'macaddress_b'
		],
		self::SECTION_SOFTWARE => ['os', 'os_full', 'os_short', 'software', 'software_full', 'software_app_a',
			'software_app_b', 'software_app_c', 'software_app_d', 'software_app_e'
		],
		self::SECTION_NETWORK => ['host_networks', 'host_netmask', 'host_router', 'oob_ip', 'oob_netmask', 'oob_router'],
		self::SECTION_LOCATION => ['location', 'site_address_a', 'site_address_b', 'site_address_c', 'site_city',
			'site_state', 'site_country', 'site_zip', 'site_rack', 'site_notes', 'location_lat', 'location_lon'
		],
		self::SECTION_LIFECYCLE => ['contract_number', 'installer_name', 'date_hw_purchase', 'date_hw_install',
			'date_hw_expiry', 'date_hw_decomm'
		],
		self::SECTION_CONTACTS => ['contact', 'poc_1_name', 'poc_1_email', 'poc_1_phone_a', 'poc_1_phone_b',
			'poc_1_cell', 'poc_1_screen', 'poc_1_notes', 'poc_2_name', 'poc_2_email', 'poc_2_phone_a', 'poc_2_phone_b',
			'poc_2_cell', 'poc_2_screen', 'poc_2_notes'
		],
		self::SECTION_NOTES => ['notes', 'url_a', 'url_b', 'url_c']
	];

	private static ?array $fields = null;

	/**
	 * @return array  db_field => ['field' => string, 'title' => string, 'nr' => int, 'section' => string]
	 *                in Zabbix's native order.
	 */
	public static function getFields(): array {
		if (self::$fields === null) {
			$section_by_field = [];

			foreach (self::SECTION_MAP as $section => $fields) {
				foreach ($fields as $field) {
					$section_by_field[$field] = $section;
				}
			}

			self::$fields = [];

			foreach (getHostInventories() as $inventory) {
				self::$fields[$inventory['db_field']] = [
					'field' => $inventory['db_field'],
					'title' => $inventory['title'],
					'nr' => (int) $inventory['nr'],
					'section' => array_key_exists($inventory['db_field'], $section_by_field)
						? $section_by_field[$inventory['db_field']]
						: self::SECTION_NOTES
				];
			}
		}

		return self::$fields;
	}

	public static function getFieldNames(): array {
		return array_keys(self::getFields());
	}

	public static function isField(string $field): bool {
		return array_key_exists($field, self::getFields());
	}

	public static function getTitle(string $field): string {
		$fields = self::getFields();

		if (array_key_exists($field, $fields)) {
			return $fields[$field]['title'];
		}

		$virtual = self::getVirtualColumns();

		return array_key_exists($field, $virtual) ? $virtual[$field] : $field;
	}

	public static function getSections(): array {
		return [
			self::SECTION_IDENTITY => _('Identity'),
			self::SECTION_HARDWARE => _('Hardware'),
			self::SECTION_SOFTWARE => _('Software'),
			self::SECTION_NETWORK => _('Network'),
			self::SECTION_LOCATION => _('Location'),
			self::SECTION_LIFECYCLE => _('Lifecycle and contract'),
			self::SECTION_CONTACTS => _('Contacts'),
			self::SECTION_NOTES => _('Notes and links')
		];
	}

	/**
	 * @return array  section => [db_field => title]
	 */
	public static function getFieldsBySection(): array {
		$result = array_fill_keys(array_keys(self::getSections()), []);

		foreach (self::SECTION_MAP as $section => $fields) {
			foreach ($fields as $field) {
				if (self::isField($field)) {
					$result[$section][$field] = self::getTitle($field);
				}
			}
		}

		// Fields unknown to the static map (future versions) go to the last section.
		foreach (self::getFields() as $field => $meta) {
			if (!array_key_exists($field, $result[$meta['section']])) {
				$result[$meta['section']][$field] = $meta['title'];
			}
		}

		return $result;
	}

	public static function getVirtualColumns(): array {
		return [
			self::COL_GROUPS => _('Host groups'),
			self::COL_STATUS => _('Status'),
			self::COL_INVENTORY_MODE => _('Inventory mode'),
			self::COL_ADDED_ON => _('Added on'),
			self::COL_COMPLETENESS => _('Completeness'),
			self::COL_TAGS => _('Tags')
		];
	}

	public static function isColumn(string $column): bool {
		return self::isField($column) || array_key_exists($column, self::getVirtualColumns());
	}

	/**
	 * Fields that are shown in the "Hardware/Software" etc. sections but are coordinates and should not be shown as
	 * plain text duplicates when a map is available.
	 */
	public static function getCoordinateFields(): array {
		return ['location_lat', 'location_lon'];
	}

	public static function getInventoryModes(): array {
		return [
			HOST_INVENTORY_DISABLED => _('Disabled'),
			HOST_INVENTORY_MANUAL => _('Manual'),
			HOST_INVENTORY_AUTOMATIC => _('Automatic')
		];
	}
}
