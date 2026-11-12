#!/usr/bin/env python3
"""
Seed (or remove) test hosts with realistic inventory data for the Inventory Plus module.

- Creates hosts named INV-<nnn>-<ROLE>-<SITE> in one host group.
- Hosts have no interfaces, templates or items: they generate no monitoring load.
- Every host is tagged generated-by=inv-test; --delete only removes hosts carrying that tag.
- Data intentionally contains inconsistencies (spelling variants, duplicate serials/MACs,
  sparse records, mixed date formats) so data-quality features can be tested.
- Idempotent: hosts that already exist are skipped.

Usage:
    set ZABBIX_API_TOKEN=<token>          (PowerShell: $env:ZABBIX_API_TOKEN = "<token>")
    python seed_test_hosts.py --url http://<server>/zabbix/api_jsonrpc.php
    python seed_test_hosts.py --url ... --delete
    python seed_test_hosts.py --url ... --dry-run

Only the Python standard library is used.
"""

import argparse
import datetime as dt
import json
import os
import random
import sys
import urllib.request

TAG_KEY, TAG_VALUE = 'generated-by', 'inv-test'
TODAY = dt.date.today()


class Api:
    def __init__(self, url, token):
        self.url, self.token, self.id = url, token, 0

    def call(self, method, params):
        self.id += 1
        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': self.id}).encode()
        req = urllib.request.Request(self.url, data=body, headers={
            'Content-Type': 'application/json-rpc',
            'Authorization': f'Bearer {self.token}'
        })
        with urllib.request.urlopen(req, timeout=60) as resp:
            data = json.loads(resp.read())
        if 'error' in data:
            raise RuntimeError(f"{method}: {data['error'].get('message')} {data['error'].get('data')}")
        return data['result']


# --- reference data ---------------------------------------------------------------------------

SITES = [
    # code, city, state, country, zip, lat, lon, address, weight
    ('SAO', 'Sao Paulo', 'SP', 'Brazil', '01310-100', -23.5614, -46.6559, 'Av. Paulista, 1000', 30),
    ('RIO', 'Rio de Janeiro', 'RJ', 'Brazil', '20040-002', -22.9035, -43.1780, 'Av. Rio Branco, 200', 18),
    ('BHZ', 'Belo Horizonte', 'MG', 'Brazil', '30130-010', -19.9245, -43.9352, 'Av. Afonso Pena, 1500', 10),
    ('CWB', 'Curitiba', 'PR', 'Brazil', '80010-000', -25.4296, -49.2713, 'Rua XV de Novembro, 300', 10),
    ('POA', 'Porto Alegre', 'RS', 'Brazil', '90010-150', -30.0277, -51.2287, 'Rua dos Andradas, 1200', 8),
    ('LIS', 'Lisbon', '', 'Portugal', '1250-096', 38.7253, -9.1500, 'Av. da Liberdade, 110', 9),
    ('NYC', 'New York', 'NY', 'United States', '10001', 40.7506, -73.9971, '450 W 33rd St', 8),
    ('FRA', 'Frankfurt', 'HE', 'Germany', '60314', 50.1106, 8.7280, 'Hanauer Landstrasse 300', 7),
]

ROLES = {
    # role: (weight, type, type_full, [(vendor, [models], hw_arch, chassis)], os options)
    'SRV': (50, 'Server', 'Rack server', [
        ('Dell', ['PowerEdge R650', 'PowerEdge R750', 'PowerEdge R640'], 'x86_64', '1U rack'),
        ('HPE', ['ProLiant DL360 Gen10', 'ProLiant DL380 Gen11'], 'x86_64', '2U rack'),
        ('Lenovo', ['ThinkSystem SR650', 'ThinkSystem SR630 V2'], 'x86_64', '1U rack'),
        ('Supermicro', ['SYS-1029P-WTR', 'SYS-620P-TRT'], 'x86_64', '1U rack'),
    ], [
        ('Linux', 'Ubuntu 22.04.4 LTS', 'Ubuntu 22.04'),
        ('Linux', 'Ubuntu 24.04.1 LTS', 'Ubuntu 24.04'),
        ('Linux', 'Red Hat Enterprise Linux 9.4', 'RHEL 9'),
        ('Linux', 'AlmaLinux 9.5', 'Alma 9'),
        ('Linux', 'Debian 12 (bookworm)', 'Debian 12'),
        ('Windows', 'Windows Server 2022 Datacenter', 'WS2022'),
        ('Windows', 'Windows Server 2019 Standard', 'WS2019'),
        ('VMware ESXi', 'VMware ESXi 8.0 U3', 'ESXi 8.0'),
    ]),
    'SW': (15, 'Switch', 'Ethernet switch', [
        ('Cisco', ['Catalyst 9300-48P', 'Catalyst 9200L-24T', 'Nexus 93180YC-FX'], 'arm64', '1U rack'),
        ('Juniper', ['EX4300-48T', 'QFX5120-48Y'], 'x86_64', '1U rack'),
        ('HPE', ['Aruba 6300M', 'Aruba 2930F'], 'arm64', '1U rack'),
    ], [
        ('IOS-XE', 'Cisco IOS XE 17.9.4', 'IOS-XE 17.9'),
        ('Junos', 'Junos OS 22.4R3', 'Junos 22.4'),
        ('ArubaOS-CX', 'ArubaOS-CX 10.13', 'AOS-CX 10.13'),
    ]),
    'RTR': (8, 'Router', 'Edge router', [
        ('Cisco', ['ISR 4331', 'ASR 1001-X'], 'x86_64', '1U rack'),
        ('Juniper', ['MX204', 'SRX380'], 'x86_64', '1U rack'),
        ('MikroTik', ['CCR2004-16G-2S+'], 'arm64', '1U rack'),
    ], [
        ('IOS-XE', 'Cisco IOS XE 17.6.5', 'IOS-XE 17.6'),
        ('Junos', 'Junos OS 23.2R1', 'Junos 23.2'),
        ('RouterOS', 'RouterOS 7.15', 'ROS 7.15'),
    ]),
    'FW': (8, 'Firewall', 'Next-generation firewall', [
        ('Fortinet', ['FortiGate 100F', 'FortiGate 200F', 'FortiGate 60F'], 'x86_64', 'Desktop'),
        ('Palo Alto Networks', ['PA-440', 'PA-3220'], 'x86_64', '1U rack'),
    ], [
        ('FortiOS', 'FortiOS 7.4.4', 'FortiOS 7.4'),
        ('PAN-OS', 'PAN-OS 11.1.2', 'PAN-OS 11.1'),
    ]),
    'STO': (6, 'Storage', 'Storage array', [
        ('NetApp', ['AFF A250', 'FAS2750'], 'x86_64', '2U rack'),
        ('Dell', ['PowerStore 500T', 'Unity XT 380'], 'x86_64', '2U rack'),
    ], [
        ('ONTAP', 'NetApp ONTAP 9.14.1', 'ONTAP 9.14'),
        ('PowerStoreOS', 'PowerStoreOS 3.6', 'PSOS 3.6'),
    ]),
    'AP': (8, 'Access point', 'Wireless access point', [
        ('Ubiquiti', ['UniFi U6 Pro', 'UniFi U6 LR'], 'arm64', 'Ceiling mount'),
        ('Cisco', ['Catalyst 9120AXI'], 'arm64', 'Ceiling mount'),
        ('HPE', ['Aruba AP-515'], 'arm64', 'Ceiling mount'),
    ], [
        ('UniFi OS', 'UniFi AP firmware 6.6.77', 'UniFi 6.6'),
        ('IOS-XE', 'Cisco IOS XE 17.9.4 (AP)', 'IOS-XE 17.9'),
        ('ArubaOS', 'ArubaOS 8.11.2', 'AOS 8.11'),
    ]),
}

FIRST = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Felipe', 'Gabriela', 'Hugo', 'Isabela', 'Joao',
         'Larissa', 'Marcos', 'Natalia', 'Otavio', 'Paula', 'Rafael', 'Sofia', 'Tiago', 'Vanessa', 'Wagner']
LAST = ['Silva', 'Souza', 'Oliveira', 'Pereira', 'Costa', 'Rodrigues', 'Almeida', 'Nascimento', 'Lima', 'Araujo']
TEAMS = ['infra', 'network', 'security', 'storage', 'platform']
ENVS = [('prod', 60), ('staging', 20), ('dev', 15), ('lab', 5)]
DEPLOY = [('Production', 60), ('Staging', 15), ('Test', 12), ('Spare', 8), ('Decommissioning', 5)]
INSTALLERS = ['TechInstall Ltda', 'NetBuild Services', 'Internal IT', 'DataCenter Pro']

# Spelling variants used to simulate dirty data (applied to ~12% of hosts).
VARIANTS = {
    'Linux': ['linux', 'LINUX', 'Linux '],
    'Windows': ['windows', 'Windows Server', 'WINDOWS'],
    'Dell': ['DELL', 'Dell Inc.', 'Dell Technologies'],
    'HPE': ['HP', 'Hewlett Packard Enterprise', 'hpe'],
    'Cisco': ['Cisco Systems', 'cisco', 'CISCO'],
    'Lenovo': ['LENOVO', 'Lenovo Group'],
    'Juniper': ['Juniper Networks', 'juniper'],
}


def wchoice(rng, pairs):
    """Weighted choice from [(value, weight), ...]."""
    return rng.choices([p[0] for p in pairs], weights=[p[1] for p in pairs])[0]


def mac(rng):
    return ':'.join(f'{rng.randrange(256):02X}' for _ in range(6))


def fmt_date(d, style):
    return d.strftime('%d/%m/%Y') if style == 'dmy' else d.isoformat()


def person(rng):
    f, l = rng.choice(FIRST), rng.choice(LAST)
    return f'{f} {l}', f'{f.lower()}.{l.lower()}@example.com', f'+55 11 9{rng.randrange(1000, 9999)}-{rng.randrange(1000, 9999)}'


def build_hosts(count, groupid, seed=42):
    rng = random.Random(seed)
    role_pairs = [(r, v[0]) for r, v in ROLES.items()]
    hosts = []
    serials, macs = [], []

    for i in range(1, count + 1):
        role = wchoice(rng, role_pairs)
        _, rtype, rtype_full, vendors, oses = ROLES[role]
        site = wchoice(rng, [(s, s[8]) for s in SITES])
        code, city, state, country, zipc, lat, lon, addr, _ = site
        vidx = rng.randrange(len(vendors))
        vendor, models, arch, chassis = vendors[vidx]
        model = rng.choice(models)
        # Servers: any OS. Other roles: OS list is aligned with the vendor list.
        os_name, os_full, os_short = rng.choice(oses) if role == 'SRV' else oses[vidx]
        env = wchoice(rng, ENVS)
        team = rng.choice(TEAMS)
        host = f'INV-{i:03d}-{role}-{code}'

        # Dirty data: spelling variants.
        if rng.random() < 0.12 and os_name in VARIANTS:
            os_name = rng.choice(VARIANTS[os_name])
        if rng.random() < 0.12 and vendor in VARIANTS:
            vendor = rng.choice(VARIANTS[vendor])

        serial = f'{vendor[:2].upper().strip()}{rng.randrange(10**7, 10**8)}'
        m = mac(rng)
        # Dirty data: duplicate serials (3) and MACs (2) copied from earlier hosts.
        if i in (23, 57, 88) and serials:
            serial = rng.choice(serials)
        if i in (41, 76) and macs:
            m = rng.choice(macs)
        serials.append(serial)
        macs.append(m)

        # Lifecycle dates. Some warranties forced to expire soon / already expired.
        purchase = dt.date(2018, 1, 1) + dt.timedelta(days=rng.randrange(0, 365 * 7))
        install = purchase + dt.timedelta(days=rng.randrange(10, 60))
        expiry = purchase + dt.timedelta(days=365 * rng.choice([3, 5]))
        if i % 11 == 0:
            expiry = TODAY + dt.timedelta(days=rng.randrange(1, 31))
        elif i % 13 == 0:
            expiry = TODAY + dt.timedelta(days=rng.randrange(31, 91))
        date_style = 'dmy' if i % 17 == 0 else 'iso'
        deployment = wchoice(rng, DEPLOY)
        decomm = (TODAY + dt.timedelta(days=rng.randrange(30, 200))) if deployment == 'Decommissioning' else None

        p1, p2 = person(rng), person(rng)
        net_third = rng.randrange(1, 250)

        inv = {
            'type': rtype,
            'type_full': rtype_full,
            'name': host.lower(),
            'alias': f'{code.lower()}-{role.lower()}-{i:02d}',
            'os': os_name,
            'os_full': os_full,
            'os_short': os_short,
            'serialno_a': serial,
            'tag': env,
            'asset_tag': f'AT-{rng.randrange(10000, 99999)}',
            'macaddress_a': m,
            'hardware': f'{vendor} {model}',
            'hardware_full': f'{vendor} {model}, {rng.choice([2, 4, 8, 16, 32])} cores, {rng.choice([16, 32, 64, 128, 256])} GB RAM',
            'software': os_short,
            'contact': f'{team}@example.com',
            'location': f'{city} DC, Rack {rng.randrange(1, 13):02d}',
            'location_lat': f'{lat + rng.uniform(-0.01, 0.01):.6f}',
            'location_lon': f'{lon + rng.uniform(-0.01, 0.01):.6f}',
            'notes': rng.choice(['', '', 'Replaced PSU in 2024.', 'Pending firmware upgrade.', 'Shared with DR site.']),
            'chassis': chassis,
            'model': model,
            'hw_arch': arch,
            'vendor': vendor,
            'contract_number': f'CT-{rng.randrange(2018, 2026)}-{rng.randrange(100, 999)}',
            'installer_name': rng.choice(INSTALLERS),
            'deployment_status': deployment,
            'url_a': f'https://wiki.example.com/assets/{host.lower()}',
            'host_networks': f'10.{rng.randrange(10, 60)}.{net_third}.0/24',
            'host_netmask': '255.255.255.0',
            'host_router': f'10.{rng.randrange(10, 60)}.{net_third}.1',
            'date_hw_purchase': fmt_date(purchase, date_style),
            'date_hw_install': fmt_date(install, date_style),
            'date_hw_expiry': fmt_date(expiry, date_style),
            'date_hw_decomm': fmt_date(decomm, date_style) if decomm else '',
            'site_address_a': addr,
            'site_city': city,
            'site_state': state,
            'site_country': country,
            'site_zip': zipc,
            'site_rack': f'R{rng.randrange(1, 13):02d}-U{rng.randrange(1, 42):02d}',
            'poc_1_name': p1[0], 'poc_1_email': p1[1], 'poc_1_phone_a': p1[2],
            'poc_1_cell': p1[2],
        }
        if role == 'SRV':
            inv.update({
                'oob_ip': f'10.200.{net_third}.{rng.randrange(2, 250)}',
                'oob_netmask': '255.255.255.0',
                'oob_router': f'10.200.{net_third}.1',
                'software_app_a': rng.choice(['nginx 1.26', 'PostgreSQL 16', 'Apache 2.4', 'Docker 27.1', 'IIS 10']),
                'software_app_b': rng.choice(['', 'Node.js 20', 'Redis 7.2', 'Java 21']),
            })
        if rng.random() < 0.5:
            inv.update({'poc_2_name': p2[0], 'poc_2_email': p2[1], 'poc_2_phone_a': p2[2]})

        # Completeness tiers: ~25% partial, ~10% sparse.
        r = rng.random()
        if r < 0.10:
            keep = {'type', 'name', 'vendor'}
            inv = {k: v for k, v in inv.items() if k in keep}
        elif r < 0.35:
            inv = {k: v for k, v in inv.items() if k in ('type', 'name') or rng.random() > 0.4}
        inv = {k: v for k, v in inv.items() if v != ''}

        # Inventory mode: mostly manual; a few automatic and a few disabled.
        if i % 25 == 0:
            inventory_mode, inv = -1, {}
        elif i % 20 == 0:
            inventory_mode = 1
        else:
            inventory_mode = 0

        h = {
            'host': host,
            'name': host,
            'groups': [{'groupid': groupid}],
            'status': 1 if rng.random() < 0.10 else 0,
            'description': f'Test host generated for the Inventory Plus module ({rtype}, {city}).',
            'tags': [
                {'tag': TAG_KEY, 'value': TAG_VALUE},
                {'tag': 'env', 'value': env},
                {'tag': 'team', 'value': team},
                {'tag': 'site', 'value': code},
            ],
            'inventory_mode': inventory_mode,
        }
        if inv:
            h['inventory'] = inv
        hosts.append(h)
    return hosts


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--url', required=True, help='Zabbix API URL, e.g. http://host/zabbix/api_jsonrpc.php')
    ap.add_argument('--group', default='Inventory test', help='Host group name (created if missing)')
    ap.add_argument('--count', type=int, default=100)
    ap.add_argument('--seed', type=int, default=42)
    ap.add_argument('--delete', action='store_true', help='Delete hosts created by this script')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    token = os.environ.get('ZABBIX_API_TOKEN')
    if not token:
        sys.exit('Set the ZABBIX_API_TOKEN environment variable.')
    api = Api(args.url, token)

    existing = api.call('host.get', {
        'output': ['hostid', 'host'],
        'search': {'host': 'INV-'}, 'startSearch': True,
        'tags': [{'tag': TAG_KEY, 'value': TAG_VALUE, 'operator': 1}],
    })

    if args.delete:
        print(f'Hosts to delete: {len(existing)}')
        if existing and not args.dry_run:
            ids = [h['hostid'] for h in existing]
            for n in range(0, len(ids), 50):
                api.call('host.delete', ids[n:n + 50])
            print('Deleted.')
        return

    groups = api.call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': [args.group]}})
    if groups:
        groupid = groups[0]['groupid']
    elif args.dry_run:
        groupid = '0'
    else:
        groupid = api.call('hostgroup.create', {'name': args.group})['groupids'][0]
        print(f'Created host group "{args.group}".')

    have = {h['host'] for h in existing}
    hosts = [h for h in build_hosts(args.count, groupid, args.seed) if h['host'] not in have]
    print(f'Existing: {len(have)}, to create: {len(hosts)}')
    if args.dry_run:
        print(json.dumps(hosts[:2], indent=2))
        return
    for n in range(0, len(hosts), 25):
        api.call('host.create', hosts[n:n + 25])
        print(f'  created {min(n + 25, len(hosts))}/{len(hosts)}')
    print('Done.')


if __name__ == '__main__':
    main()
