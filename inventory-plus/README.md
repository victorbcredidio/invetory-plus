# Inventory Plus — Zabbix frontend module

Read-only views of host inventory data, added under **Inventory** after the built-in *Overview* and *Hosts* pages.

| Menu entry | What it does |
|---|---|
| **Inventory › Explorer** | Host table with any of the 70 inventory fields as columns. The **Columns** button (cog icon, top-right of the table) opens a *Column list* popup in the style of Zabbix 8.0's table options, and it also works on 7.0 and 7.4. It has a checkbox for every inventory field plus Host groups, Status, Inventory mode, Added on, Completeness and Tags, drag-and-drop ordering, a search box, and *Reset layout*. Filters: host groups, host name, status, inventory mode, tags, and any number of inventory-field conditions combined with AND/OR (*contains*, *equals*, *does not contain*, *does not equal*, *is empty*, *is not empty*). Every column can be sorted. Exports to CSV (visible columns or all fields). Columns and filters are remembered per user. |
| **Inventory › Analytics** | Hosts grouped by any inventory field, shown as a bar list with %, an optional "Not set" bucket and an option that merges spelling variants. Also a two-field cross-tab (heatmap), headline numbers, and the fill rate of every field. Click any value to open the matching hosts in Explorer. |
| **Host card** (opened from Explorer) | The host's inventory grouped into sections (Identity, Hardware, Software, Network, Location with a map, Lifecycle and contract, Contacts, Notes and links). Also shows completeness, problems by severity, interfaces, which fields are filled by items, and "Added on". |

### "Added on"
Zabbix doesn't store when a host was created, and this module doesn't change the database. Instead, "Added on" comes from the **audit log**: the *Add* action on the *Host* resource.
- Dates are only available for as long as the audit log keeps them (*Administration › Audit log › Data storage period*), and only while audit logging is enabled.
- Hosts with no audit record show **"before ‹oldest audit record date›"**.
- When sorting by "Added on", hosts without a date are ordered by host ID. IDs only increase, so this is still oldest-to-newest.
- The audit log is read with a single `SELECT`, and only for hosts the user already has permission to see.

The module never changes hosts or inventory. It only writes each user's own UI preferences (chosen columns, filters, sort order), the same way built-in pages do.

## Compatibility
- Zabbix **7.0 LTS, 7.4, 8.0 LTS** (PHP 8.0+).
- Uses only framework APIs present in all three versions: `manifest_version` 2, `CController`, `CHtmlPage`, `CFilter`, `CTagFilterFieldHelper`, and the bundled Leaflet library.
- The CSV export returns JSON and the browser builds the file. This is because 8.0 removed `layout.csv`.
- Module CSS/JS loads only on the module's own pages, not on every frontend page.

## Installation
1. Copy the `inventory-plus` folder into the frontend's `modules/` directory, which sits next to `zabbix.php`. For example:
   - RHEL / Alma / Rocky packages: `/usr/share/zabbix/ui/modules/inventory-plus` (7.4+), or `/usr/share/zabbix/modules/inventory-plus`
   - Debian / Ubuntu packages: `/usr/share/zabbix/modules/inventory-plus` (or `/usr/share/zabbix/ui/modules/...` on newer packages)
   - Docker (`zabbix-web-*`): mount it into `/usr/share/zabbix/modules/inventory-plus`
2. Make the files readable by the web server user (e.g. `chmod -R a+rX`). On SELinux systems, run `restorecon -R <path>`.
3. In the frontend, go to **Administration › General › Modules**, click **Scan directory**, then **Enable** *Inventory Plus*.
4. Optional: in **Users › User roles**, check that the roles have:
   - *Access to modules › Inventory Plus*
   - *Inventory › Hosts* (for Explorer and the Host card)
   - *Inventory › Overview* (for Analytics)

## Configuration (`manifest.json` → `config`)
| Key | Default | Meaning |
|---|---|---|
| `added_on_visible_to` | `"all"` | `"all"`: everyone sees "Added on" for the hosts they can access. `"super_admin"`: only Super admins see it. |
| `default_columns` | type, vendor, model, os, serialno_a, site_city, `_added_on`, `_completeness` | Explorer columns for users who haven't chosen their own. Virtual columns: `_groups`, `_status`, `_inventory_mode`, `_added_on`, `_completeness`, `_tags`. |

Zabbix copies the `config` block into its database when the module is first registered (*Scan directory*). After that, the database copy is used and edits to `manifest.json` are ignored. To change it later, either:
- use the API, e.g. `module.update` with `{"moduleid": "<id>", "config": {...}}`, or
- remove the module entry and scan the directory again.

## Files
```
inventory-plus/
├── manifest.json            module definition, actions, default config
├── Module.php               menu registration
├── actions/                 controllers (Explorer, ExplorerExport, Analytics, HostCard)
├── includes/                shared logic (field catalogue, queries, audit lookup, rendering)
├── views/                   page views + views/js (page-specific JS)
└── assets/css/              styles (light + dark themes)
```
