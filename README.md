# Inventory Plus for Zabbix

A frontend module that gives Zabbix host inventory better pages: a configurable **Explorer**, an **Analytics** page, and a sectioned **Host card**. It also shows **when each host was added**.

It works on **Zabbix 7.0 LTS, 7.4 and 8.0 LTS**, never changes the database schema, and only reads data.

---

## Why

Zabbix ships two inventory pages:

| Built-in page | Limitation |
|---|---|
| **Inventory › Overview** | Groups by a single field and shows only a value/count table. No charts, no percentages, no second field. Hosts with an empty value are not shown, so gaps stay invisible. |
| **Inventory › Hosts** | Fixed set of 8 columns. Filters on one inventory field only. No export. To see all ~70 fields you open each host one by one, as an unsorted list. |

Inventory Plus keeps both pages and adds new ones under the same **Inventory** menu.

## Features

### Inventory › Explorer
- **Choose any columns.** The *Columns* button (cog icon) opens a **Column list** popup, similar to Zabbix 8.0's table options:
  - a checkbox for each of the 70 inventory fields, grouped by section;
  - also *Host groups*, *Status*, *Inventory mode*, *Added on*, *Completeness* and *Tags*;
  - drag displayed columns to reorder them;
  - search box, *Reset layout*.
- **Filters:** host groups (including subgroups), host name, status, inventory mode (any / manual or automatic / manual / automatic / disabled), and tags. You can also add any number of **inventory field conditions** combined with **AND/OR**. Operators: *contains*, *equals*, *does not contain*, *does not equal*, *is empty*, *is not empty*. Comparisons ignore letter case and extra spaces.
- **Sort by any column.** Empty values always go last.
- **Completeness meter:** how many of the 70 fields each host has filled.
- **CSV export** of every matching host, either with the visible columns or with all fields. The export is UTF-8 (opens correctly in Excel) and protected against spreadsheet formula injection.
- Filters, columns, column order and sorting are remembered per user.

### Inventory › Analytics
- **Distribution** of hosts by any inventory field, shown as a bar list with counts and percentages:
  - *Merge spelling variants* treats `Linux`, `linux` and `Linux ` as one value. Values that had variants get a badge listing each spelling.
  - A **Not set** bar shows how many hosts have no value.
  - *Show top N*, with the rest grouped as *Other*.
- **Cross-tab heatmap** of two fields, e.g. *Vendor × Site city* or *OS × Deployment status*.
- **Summary figures:** hosts in scope, average completeness, distinct values, number of hosts with the field not set.
- **Field fill rate:** what percentage of hosts has each of the 70 fields filled. Click a field to analyze it.
- **Drill-down:** click any value or heatmap cell to open the Explorer filtered to exactly those hosts.

### Host card (open a host from the Explorer)
- Inventory grouped into sections: **Identity, Hardware, Software, Network, Location, Lifecycle and contract, Contacts, Notes and links**. Empty fields are hidden by default, with a toggle to show them.
- **Map** of the host's latitude/longitude, using the tile server set in *Administration › General › Geographical maps*.
- Email addresses, phone numbers and URLs are clickable.
- Marks the fields that are **populated by items** (automatic inventory mode) and names the item.
- Status, inventory mode, **Added on**, completeness, active **problems by severity**, interfaces, item/trigger counts.
- Links to *Problems*, *Latest data* and *Configure host*. The configure link opens the correct form for the running Zabbix version.

### "Added on": when was the host created?
Zabbix doesn't store a creation timestamp for hosts. Inventory Plus takes it from the **audit log**: the *Add* action on the *Host* resource, including hosts created by discovery or autoregistration.
- It's only available for as long as the audit log keeps its entries (*Administration › Audit log*), and only while audit logging is on.
- Older hosts are shown as **"before ‹date of the oldest audit record›"**.
- Sorting by *Added on* still runs oldest to newest, because host IDs only increase over time.
- Super admins can also see **who** created the host (hover the date).

---

## Compatibility

| Zabbix | Status |
|---|---|
| 7.0 LTS | Supported |
| 7.2 / 7.4 | Supported (developed and tested on 7.4.16) |
| 8.0 LTS | Supported |
| 6.x and older | Not supported |

- **PHP:** 8.0 or newer, which is what Zabbix 7.0+ requires anyway.
- **Database:** no schema changes. All SQL is read-only.

---

## Installation

1. **Download** this repository and copy the `inventory-plus` folder into your frontend's `modules/` directory, which sits next to `zabbix.php`:

   | Installation | Path |
   |---|---|
   | RHEL / Alma / Rocky packages (7.4+) | `/usr/share/zabbix/ui/modules/inventory-plus` |
   | Older RHEL-based or Debian / Ubuntu packages | `/usr/share/zabbix/modules/inventory-plus` |
   | Docker `zabbix-web-*` images | mount into `/usr/share/zabbix/modules/inventory-plus` |

   ```bash
   cd /usr/share/zabbix/ui/modules          # adjust to your path
   git clone https://github.com/victorbcredidio/invetory-plus.git /tmp/zip
   cp -r /tmp/zip/inventory-plus .
   chmod -R a+rX inventory-plus
   restorecon -R inventory-plus 2>/dev/null || true   # SELinux systems
   ```

2. In the frontend, open **Administration › General › Modules**, click **Scan directory**, and **Enable** *Inventory Plus*.

3. Reload the page. **Inventory** now has two extra entries: **Explorer** and **Analytics**.

4. *(Optional)* In **Users › User roles**, check which roles can use it:
   - *Access to modules › Inventory Plus* controls whether the module is loaded at all.
   - *UI elements › Inventory › Hosts* is required for Explorer and the Host card.
   - *UI elements › Inventory › Overview* is required for Analytics.

### Upgrading
Replace the `inventory-plus` folder with the new version and reload the browser. The module stays enabled, and users keep their saved columns and filters.

### Uninstalling
Disable the module in *Administration › General › Modules*, then delete the folder. Saved per-user preferences (rows in the `profiles` table whose key starts with `web.inventoryplus.`) are harmless and can be left in place.

---

## Using it

### Explorer
1. Open **Inventory › Explorer**.
2. Set filters. To filter on inventory data, use **Inventory fields › Add**. For example, *Vendor contains "dell"* **AND** *Date HW expiry is not empty*.
3. Click **Columns (N)** above the table:
   - tick the fields you want to see;
   - drag them into the order you want;
   - click **Apply**.

   *Reset layout* returns to the default columns.
4. Click a column header to sort.
5. **Export CSV** downloads every matching host with the visible columns. **Export CSV (all fields)** includes everything.
6. Click a host name to open its **Host card**.

> The table shows up to the frontend *search/filter limit* (Administration › General › GUI, default 1000 rows), like other Zabbix lists. The CSV export always contains every matching host.

### Analytics
1. Open **Inventory › Analytics** and, if you like, narrow the scope with the filter (host groups, status, tags).
2. Pick **Group by**, e.g. *OS*. Optionally pick **Then by**, e.g. *Site city*, to get the heatmap.
3. Use **Merge spelling variants** to find dirty data. The *n variants* badge shows each spelling and how often it's used.
4. Click any bar, the *Not set* bar, or a heatmap cell to open those hosts in the Explorer.
5. Use **Field fill rate** to see which fields your team actually fills in.

---

## How it works

```
Browser ──► zabbix.php?action=inventoryplus.*  ──►  Controller (actions/)
                                                     │  permissions: user role + host permissions
                                                     ├─ API::Host()->get(...)   ← all host/inventory data
                                                     ├─ auditlog (read-only)   ← "Added on"
                                                     └─ CProfile               ← per-user columns/filters
                                                    View (views/) ──► HTML + page-only JS/CSS
```

- **Standard module layout:** `manifest.json` declares four actions: `inventoryplus.explorer`, `inventoryplus.explorer.export`, `inventoryplus.analytics` and `inventoryplus.host`. `Module.php` adds the menu entries after the built-in *Overview* and *Hosts*, and only if the user's role can see those.
- **Permissions come from Zabbix itself.** Host and inventory data is read through the internal Host API, so users only ever see hosts they have permission to read. Each page also checks the matching *Inventory* UI permission.
- **Filtering and grouping happen in PHP.** The API can't do *is empty*, *does not contain*, AND/OR across inventory fields, or group-by. The module loads only the inventory fields it needs for the matching hosts and evaluates those in PHP, the same way the built-in Overview page counts values.
- **"Added on"** is one indexed, read-only query on the `auditlog` table, limited to host IDs the API has already returned for the current user. The audit API itself is limited to Super admins, so the direct query is what lets other users see creation dates for their own hosts. Set `added_on_visible_to` to `super_admin` if you'd rather not allow that.
- **Preferences** are stored with Zabbix's own per-user profile mechanism (`CProfile`), the same one built-in pages use for filters.
- **CSS and JS load only on the module's pages.** They are added from the views, not from `manifest.json` (which would load them on every frontend page). The stylesheet follows light/dark themes via the `color-scheme` attribute Zabbix sets on `<html>`. The map uses the Leaflet library bundled with Zabbix.
- **Version differences are detected at runtime, not hard-coded:**
  - **CSV export** returns JSON and the browser builds the file, because 8.0 replaced the `layout.csv` mechanism.
  - **Configure host** asks Zabbix's router whether `host.edit` is a full page (7.0) or a popup (7.2+), and links accordingly.
  - **Column list** is self-contained. 8.0's table framework doesn't exist in 7.x.

---

## Configuration

Set in `inventory-plus/manifest.json` → `config`:

| Key | Default | Meaning |
|---|---|---|
| `added_on_visible_to` | `"all"` | `"all"`: everyone sees *Added on* for the hosts they can access. `"super_admin"`: only Super admins see it. |
| `default_columns` | `type, vendor, model, os, serialno_a, site_city, _added_on, _completeness` | Explorer columns for users who haven't chosen their own. Inventory field names are the [host inventory API field names](https://www.zabbix.com/documentation/current/en/manual/api/reference/host/object#host-inventory). Virtual columns: `_groups`, `_status`, `_inventory_mode`, `_added_on`, `_completeness`, `_tags`. |

> Zabbix copies `config` into its database when the module is first registered (*Scan directory*), and the database copy is used from then on. To change it later, either:
> - call the API, e.g. `module.update` with `{"moduleid": "<id>", "config": {...}}`, or
> - remove the module entry and scan again.

---

## Test data

`dev/seed_test_hosts.py` creates realistic test hosts so you can try the module. It uses only the Python standard library.

- **Hosts:** 100 hosts named `INV-<nnn>-<ROLE>-<SITE>` in one host group.
  - No interfaces or items, so they put no load on monitoring.
  - Tagged `generated-by: inv-test`.
- **Inventory data:** varied vendors, models, OSes, sites (with coordinates), racks, contacts and lifecycle dates.
- **Deliberately messy data**, for testing the data-quality features:
  - spelling variants;
  - duplicate serial numbers and MAC addresses;
  - nearly empty hosts;
  - a few hosts with inventory disabled or set to automatic;
  - warranties that are expired or expiring soon.

```bash
export ZABBIX_API_TOKEN=<api token of a user allowed to create hosts>
python3 dev/seed_test_hosts.py --url https://zabbix.example.com/zabbix/api_jsonrpc.php            # create
python3 dev/seed_test_hosts.py --url https://zabbix.example.com/zabbix/api_jsonrpc.php --dry-run  # preview
python3 dev/seed_test_hosts.py --url https://zabbix.example.com/zabbix/api_jsonrpc.php --delete   # remove them
```

Running it twice doesn't create duplicates. `--delete` only removes hosts that carry the `generated-by: inv-test` tag.

---

## Limitations and ideas

- **Memory use:** Explorer and Analytics load the requested inventory fields for every matching host. On very large installations (tens of thousands of hosts), narrow the scope with host groups, or raise PHP `memory_limit` if needed.
- **"Added on"** depends on audit log retention, as described above.
- **Possible next steps:**
  - duplicate detection (serial numbers, MACs, asset tags);
  - a warranty / lifecycle view with date parsing;
  - dashboard widgets (inventory distribution, completeness, warranties expiring).

## Repository layout

```
inventory-plus/              ← the module (copy this folder into Zabbix)
├── manifest.json            module definition, actions, default config
├── Module.php               menu registration
├── actions/                 controllers: Explorer, ExplorerExport, Analytics, HostCard
├── includes/                field catalogue, querying/filtering, audit lookup, rendering helpers
├── views/                   page views; views/js holds page-specific JavaScript
├── assets/css/              styles (light and dark themes)
└── README.md                short install notes shipped with the module
dev/
└── seed_test_hosts.py       test data generator
LICENSE                      GNU AGPL-3.0
```

## License

Inventory Plus is released under the [GNU Affero General Public License v3.0](LICENSE), the same license as Zabbix 7.0 and later.
