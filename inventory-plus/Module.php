<?php declare(strict_types = 0);
/*
** Inventory Plus - Zabbix frontend module
** Compatible with Zabbix 7.0, 7.4 and 8.0.
**/

namespace Modules\InventoryPlus;

use APP,
	CMenuItem,
	CRoleHelper,
	CWebUser;

use Zabbix\Core\CModule;

class Module extends CModule {

	/**
	 * Adds "Explorer" and "Analytics" under the Inventory menu section, after the built-in entries.
	 * The section is only extended if it exists (i.e. the user role has access to at least one inventory page).
	 */
	public function init(): void {
		// The main menu is only registered for logged-in users (not on the login page or for API-like requests).
		if (!APP::Component()->has('menu.main')) {
			return;
		}

		$inventory = APP::Component()->get('menu.main')->find(_('Inventory'));

		if ($inventory === null) {
			return;
		}

		$submenu = $inventory->getSubMenu();

		if (CWebUser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)) {
			$submenu->add(
				(new CMenuItem(_('Explorer')))
					->setAction('inventoryplus.explorer')
					->setAliases(['inventoryplus.host'])
			);
		}

		if (CWebUser::checkAccess(CRoleHelper::UI_INVENTORY_OVERVIEW)) {
			$submenu->add(
				(new CMenuItem(_('Analytics')))->setAction('inventoryplus.analytics')
			);
		}
	}
}
