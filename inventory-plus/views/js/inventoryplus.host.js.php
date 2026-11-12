<?php declare(strict_types = 0);
/*
** Inventory Plus - Host card view JavaScript.
**
** @var CView $this
**/
?>

<script>
	const view = new class {

		init({location, map_config, marker_title}) {
			this._initEmptyToggle();

			if (location !== null && map_config !== null) {
				this._initMap(location, map_config, marker_title);
			}
		}

		_initEmptyToggle() {
			const checkbox = document.getElementById('ip-show-empty-fields');
			const cards = document.getElementById('ip-cards');
			const key = 'inventoryplus.host.show_empty';

			if (checkbox === null || cards === null) {
				return;
			}

			try {
				checkbox.checked = localStorage.getItem(key) === '1';
			}
			catch (e) {
				// Storage unavailable: default is "hidden".
			}

			const apply = () => cards.classList.toggle('ip-show-empty', checkbox.checked);

			checkbox.addEventListener('change', () => {
				apply();

				try {
					localStorage.setItem(key, checkbox.checked ? '1' : '0');
				}
				catch (e) {
					// Ignore.
				}
			});

			apply();
		}

		_initMap(location, config, title) {
			const container = document.getElementById('ip-map');

			if (container === null || typeof L === 'undefined') {
				return;
			}

			const map = L.map(container, {scrollWheelZoom: false}).setView([location.lat, location.lon], 14);

			L.tileLayer(config.tile_url, {
				maxZoom: config.max_zoom,
				attribution: config.attribution
			}).addTo(map);

			// Circle marker: no image assets required.
			L.circleMarker([location.lat, location.lon], {
				radius: 9,
				weight: 2,
				color: '#ffffff',
				fillColor: '#0275b8',
				fillOpacity: 0.95
			})
				.bindTooltip(title)
				.addTo(map);

			// Cards may resize after fonts/layout settle.
			setTimeout(() => map.invalidateSize(), 200);
		}
	};
</script>
