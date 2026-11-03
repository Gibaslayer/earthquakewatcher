/**
 * app.js - Browser logic of EarthquakeWatcher.
 *
 * Contents:
 *   - mobile navigation menu
 *   - Leaflet map on the home page (markers, detail panel, magnitude filter)
 *   - "add / remove favourite" buttons, sent to api.php with fetch()
 *
 * Data comes from the server through window.EW (set in core.php by pageFooter()).
 */
(function () {
    'use strict';

    const EW = window.EW || {};
    const I18N = EW.i18n || {};
    const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

    /**
     * Returns a CSS colour variable value (for example "--accent") from the stylesheet.
     *
     * @param {string} name Variable name, including the leading dashes.
     * @returns {string} Its computed value.
     */
    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    /**
     * Returns the CSS class matching a magnitude: green below 4, amber from 4 to 5.9, red from 6.
     *
     * @param {number} magnitude Earthquake magnitude.
     * @returns {string} 'mag-low', 'mag-med' or 'mag-high'.
     */
    function magClass(magnitude) {
        if (magnitude >= 6) return 'mag-high';
        if (magnitude >= 4) return 'mag-med';
        return 'mag-low';
    }

    /**
     * Returns the colour of a magnitude, read from the stylesheet variables.
     *
     * @param {number} magnitude Earthquake magnitude.
     * @returns {string} CSS colour.
     */
    function magColor(magnitude) {
        return cssVar('--' + magClass(magnitude));
    }

    /**
     * Converts a UTC date string from the database ("YYYY-MM-DD HH:MM:SS") into a readable local string.
     *
     * @param {string} dateString UTC date.
     * @returns {string} Formatted date followed by "UTC".
     */
    function formatDate(dateString) {
        const date = new Date(String(dateString).replace(' ', 'T') + 'Z');
        const locale = EW.lang === 'en' ? 'en-GB' : 'fr-FR';
        return date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }) + ' UTC';
    }

    /**
     * Creates an HTML element with attributes and children, without using innerHTML.
     *
     * @param {string} tag Tag name.
     * @param {Object} attrs Attributes. The special keys "text" (text content) and "class" are handled.
     * @param {Node[]} children Child nodes to append.
     * @returns {HTMLElement} The new element.
     */
    function element(tag, attrs, children) {
        const node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') node.textContent = attrs[key];
            else if (key === 'class') node.className = attrs[key];
            else node.setAttribute(key, attrs[key]);
        });
        (children || []).forEach(function (child) { node.appendChild(child); });
        return node;
    }

    /**
     * Builds the whole map and its panels (home page).
     *
     * @param {HTMLElement} mapBox The element that will contain the map.
     */
    function setupMap(mapBox) {
        const quakes = EW.quakes || [];
        let favorites = EW.favorites || [];
        let favIds = EW.favIds || favorites.map(function (f) { return f.id; });
        let minMag = 0;
        let onlyFavs = false;
        let selectedId = null;
        let current = [];
        const markers = new Map();

        const minSelect = document.getElementById('minmag');          // home page only
        const favFilter = document.getElementById('toggleFavFilter');  // home page, signed-in users
        const statsBox = document.getElementById('stats');
        const topList = document.getElementById('topList');
        const detail = document.getElementById('quakeDetail');
        if (minSelect) minMag = parseFloat(minSelect.value) || 0;

        const map = L.map(mapBox, { minZoom: 2, maxZoom: 12, worldCopyJump: true, zoomSnap: 0.5 }).setView([20, 10], 2);
        L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 12 }).addTo(map);
        const markerLayer = L.layerGroup().addTo(map);

        /**
         * Updates the statistics bar (count, strongest, most recent) for the current selection.
         */
        function renderStats() {
            if (!statsBox) return;
            statsBox.replaceChildren();
            const add = function (label, value) {
                statsBox.append(element('div', {}, [element('dt', { text: label }), element('dd', { text: value })]));
            };
            add(I18N.js_events, String(current.length));
            if (current.length) {
                const strongest = current.reduce(function (a, b) { return b.magnitude > a.magnitude ? b : a; });
                add(I18N.js_strongest, 'M ' + strongest.magnitude.toFixed(1));
                add(I18N.js_latest, formatDate(current[0].date));
            }
        }

        /**
         * Fills the "strongest earthquakes" list with the five largest of the current selection.
         */
        function renderTop() {
            if (!topList) return;
            topList.replaceChildren();
            if (!current.length) {
                topList.append(element('li', { class: 'muted', text: I18N.js_none }));
                return;
            }
            current.slice().sort(function (a, b) { return b.magnitude - a.magnitude; }).slice(0, 5).forEach(function (quake) {
                const button = element('button', { type: 'button', class: 'top-item' }, [
                    element('span', { class: 'badge-mag ' + magClass(quake.magnitude), text: 'M ' + quake.magnitude.toFixed(1) }),
                    element('span', { class: 'top-place', text: quake.lieu }),
                    element('span', { class: 'top-date muted', text: formatDate(quake.date) })
                ]);
                button.addEventListener('click', function () {
                    select(quake.id, true);
                    detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
                topList.append(element('li', {}, [button]));
            });
        }

        /**
         * Shows the detail panel of one earthquake, with its favourite button and USGS link.
         *
         * @param {Object|null} quake Earthquake to show, or null to show the hint.
         */
        function renderDetail(quake) {
            detail.replaceChildren();
            if (!quake) {
                detail.append(element('p', { class: 'muted', text: I18N.js_hint }));
                return;
            }
            const isFav = favIds.indexOf(quake.id) !== -1;
            const actions = element('div', { class: 'detail-actions' });
            if (EW.logged) {
                actions.append(element('button', {
                    type: 'button',
                    class: 'btn-fav btn-fav-action' + (isFav ? ' active' : ''),
                    text: isFav ? I18N.fav_exists : I18N.btn_add_fav,
                    'data-id': quake.id,
                    'data-lieu': quake.lieu,
                    'data-mag': quake.magnitude,
                    'data-date': quake.date,
                    'data-lat': quake.lat,
                    'data-lon': quake.lon
                }));
            } else {
                actions.append(element('a', { href: 'compte.php', text: I18N.js_login_to_fav }));
            }
            detail.append(
                element('div', { class: 'detail-mag ' + magClass(quake.magnitude) }, [
                    element('span', { text: 'M' }),
                    element('strong', { text: quake.magnitude.toFixed(1) })
                ]),
                element('div', { class: 'detail-body' }, [
                    element('h2', { text: quake.lieu }),
                    element('p', { class: 'muted', text: formatDate(quake.date) + ' - ' + quake.lat.toFixed(2) + ', ' + quake.lon.toFixed(2) }),
                    actions
                ])
            );
        }

        /**
         * Returns the Leaflet style of a marker: size from the magnitude, highlighted when selected.
         *
         * @param {Object} quake Earthquake.
         * @param {boolean} selected True if this marker is the selected one.
         * @returns {Object} Leaflet path options.
         */
        function markerStyle(quake, selected) {
            return {
                radius: Math.max(5, Math.min(22, (quake.magnitude - 1.5) * 3)),
                fillColor: magColor(quake.magnitude),
                fillOpacity: selected ? 0.95 : 0.7,
                color: selected ? cssVar('--ink') : '#ffffff',
                weight: selected ? 3 : 1
            };
        }

        /**
         * Selects an earthquake: highlights its marker, shows its details and optionally moves the map to it.
         *
         * @param {string} id Earthquake id.
         * @param {boolean} fly True to animate the map to the earthquake (zooming in if needed).
         */
        function select(id, fly) {
            const previous = markers.get(selectedId);
            if (previous) previous.marker.setStyle(markerStyle(previous.quake, false));
            selectedId = id;

            const found = markers.get(id);
            const quake = found ? found.quake : ([].concat(quakes, favorites).find(function (q) { return q.id === id; }) || null);
            if (found) {
                found.marker.setStyle(markerStyle(quake, true));
                found.marker.bringToFront();
                if (fly) map.flyTo([quake.lat, quake.lon], Math.max(map.getZoom(), 4), { duration: 0.8 });
                else map.panTo([quake.lat, quake.lon]);
            }
            renderDetail(quake);
        }

        /**
         * Redraws everything from the current filters: markers, statistics, top list.
         * The home page filters the live feed; "only favourites" uses the saved favourites.
         */
        function refresh() {
            const source = onlyFavs ? favorites : quakes;
            current = source
                .filter(function (q) { return q.magnitude >= minMag; })
                .sort(function (a, b) { return b.date.localeCompare(a.date); });

            markerLayer.clearLayers();
            markers.clear();
            // Draw small quakes first so that the strong ones stay visible on top.
            current.slice().sort(function (a, b) { return a.magnitude - b.magnitude; }).forEach(function (quake) {
                const marker = L.circleMarker([quake.lat, quake.lon], markerStyle(quake, quake.id === selectedId)).addTo(markerLayer);
                marker.on('click', function () { select(quake.id, false); });
                // Keyboard access: markers can be reached with Tab and activated with Enter or Space.
                const node = marker.getElement();
                if (node) {
                    node.setAttribute('tabindex', '0');
                    node.setAttribute('role', 'button');
                    node.setAttribute('aria-label', 'M ' + quake.magnitude.toFixed(1) + ' - ' + quake.lieu);
                    node.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            select(quake.id, false);
                        }
                    });
                }
                markers.set(quake.id, { marker: marker, quake: quake });
            });

            if (selectedId && !markers.has(selectedId)) {
                selectedId = null;
                renderDetail(null);
            }
            renderStats();
            renderTop();
        }

        if (minSelect) {
            minSelect.addEventListener('change', function () {
                minMag = parseFloat(minSelect.value) || 0;
                refresh();
            });
        }
        if (favFilter) {
            favFilter.addEventListener('click', function () {
                onlyFavs = !onlyFavs;
                favFilter.classList.toggle('active', onlyFavs);
                favFilter.setAttribute('aria-pressed', String(onlyFavs));
                favFilter.textContent = onlyFavs ? I18N.filter_all : I18N.filter_only_favs;
                refresh();
            });
        }

        refresh();

        // Zoom to the earthquakes when there are few of them (offline demo data).
        if (current.length && current.length < 30) {
            map.fitBounds(L.latLngBounds(current.map(function (q) { return [q.lat, q.lon]; })).pad(0.2), { maxZoom: 5 });
        }

        // Keep favourites in sync when a button is clicked (see handleFavouriteClick).
        mapBox.updateFavourites = function (id, added, quake) {
            favIds = added ? favIds.concat(id) : favIds.filter(function (x) { return x !== id; });
            favorites = added
                ? favorites.concat([quake])
                : favorites.filter(function (f) { return f.id !== id; });
            refresh();
        };
    }

    /**
     * Handles a click on an "add / remove favourite" button: calls api.php and updates the page.
     *
     * @param {HTMLElement} button The button that was clicked (it carries the data-* attributes).
     */
    async function handleFavouriteClick(button) {
        const data = button.dataset;
        button.disabled = true;
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'toggle_fav',
                    seisme_id: data.id,
                    lieu: data.lieu,
                    magnitude: data.mag,
                    date: data.date,
                    lat: data.lat,
                    lon: data.lon
                })
            });
            const result = await response.json();
            if (!response.ok) {
                alert(result.error || I18N.js_err_update);
                return;
            }

            const added = result.status === 'added';
            // Update every button for this earthquake on the page.
            document.querySelectorAll('.btn-fav-action').forEach(function (other) {
                if (other.dataset.id !== data.id) return;
                other.classList.toggle('active', added);
                other.textContent = added ? I18N.fav_exists : I18N.btn_add_fav;
            });

            const page = document.body.dataset.page;
            if (page === 'compte' && !added) {
                removeFavouriteRow(button);
            } else {
                const mapBox = document.getElementById('worldMap');
                if (mapBox && mapBox.updateFavourites) {
                    mapBox.updateFavourites(data.id, added, {
                        id: data.id,
                        lieu: data.lieu,
                        magnitude: parseFloat(data.mag),
                        date: data.date,
                        lat: parseFloat(data.lat),
                        lon: parseFloat(data.lon)
                    });
                }
            }
        } catch (error) {
            alert(I18N.js_err_server);
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Removes a favourite row from the account page and updates the counter. Shows a message when the list is empty.
     *
     * @param {HTMLElement} button The "remove" button inside the row.
     */
    function removeFavouriteRow(button) {
        const tbody = button.closest('tbody');
        const row = button.closest('tr');
        if (row) row.remove();
        const counter = document.getElementById('favCount');
        if (counter) counter.textContent = Math.max(0, Number(counter.textContent) - 1);
        if (tbody && !tbody.children.length) {
            tbody.append(element('tr', {}, [element('td', { colspan: '4', class: 'muted center', text: I18N.no_favs_msg })]));
        }
    }

    /**
     * Sets up the page once the HTML is loaded: mobile menu, map (if present) and favourite buttons.
     */
    function init() {
        const burger = document.getElementById('burgerBtn');
        const nav = document.getElementById('mainNav');
        if (burger && nav) {
            burger.addEventListener('click', function () {
                burger.setAttribute('aria-expanded', String(nav.classList.toggle('open')));
            });
        }

        const mapBox = document.getElementById('worldMap');
        if (mapBox && window.L) {
            setupMap(mapBox);
        }

        // Favourite buttons (detail panel and account page).
        document.addEventListener('click', function (event) {
            const button = event.target.closest('.btn-fav-action');
            if (button) handleFavouriteClick(button);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
