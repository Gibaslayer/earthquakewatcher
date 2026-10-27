/**
 * EarthquakeWatcher - logique client
 * Thème jour/nuit, menu mobile, favoris (AJAX) et carte Leaflet avec fiche détaillée.
 */
(function () {
    'use strict';

    const EW = window.EW || {};
    const I18N = EW.i18n || {};

    // Contours simplifiés des continents, en [longitude, latitude].
    // Dessinés sous les tuiles : la carte reste lisible si les tuiles ne se chargent pas (hors ligne).
    const LAND = [
        [[-168,66],[-162,70],[-141,70],[-125,70],[-95,72],[-82,68],[-62,60],[-56,52],[-66,45],[-76,38],[-81,31],[-80,25],[-83,29],[-90,30],[-97,26],[-97,20],[-87,21],[-88,16],[-83,10],[-78,8],[-80,7],[-86,11],[-92,15],[-105,20],[-110,24],[-112,31],[-117,32],[-124,40],[-124,48],[-135,58],[-150,60],[-165,55],[-158,58]],
        [[-73,78],[-60,82],[-30,83],[-20,75],[-22,70],[-40,65],[-44,60],[-52,64],[-56,70],[-68,76]],
        [[-78,8],[-72,12],[-62,10],[-52,5],[-50,0],[-35,-6],[-39,-14],[-41,-22],[-48,-26],[-58,-35],[-65,-41],[-68,-52],[-70,-55],[-74,-50],[-73,-37],[-71,-20],[-76,-14],[-81,-5],[-80,0],[-77,4]],
        [[-9,37],[-9,43],[-2,44],[-4,48],[2,51],[8,54],[10,57],[12,55],[20,55],[22,60],[30,60],[22,65],[17,62],[18,59],[12,56],[6,58],[5,62],[14,68],[25,71],[40,67],[60,69],[70,73],[100,77],[130,71],[160,70],[180,68],[180,65],[165,60],[156,51],[150,59],[140,54],[135,43],[129,35],[126,38],[121,40],[122,31],[120,23],[108,21],[106,10],[100,13],[103,1],[98,8],[94,17],[88,22],[80,15],[77,8],[73,18],[67,25],[57,25],[56,26],[51,30],[48,29],[50,25],[56,26],[58,21],[52,16],[43,13],[39,21],[35,28],[34,31],[36,36],[27,37],[26,40],[23,38],[22,37],[19,41],[13,45],[16,41],[18,40],[16,38],[12,42],[8,44],[3,43],[-1,37]],
        [[-17,21],[-10,30],[-6,36],[10,37],[11,33],[20,31],[32,31],[35,28],[43,12],[51,12],[41,-2],[40,-15],[35,-24],[32,-29],[27,-34],[19,-34],[14,-22],[12,-6],[9,4],[-8,4],[-17,14]],
        [[114,-22],[122,-18],[130,-12],[137,-12],[142,-11],[146,-19],[153,-26],[150,-37],[141,-38],[135,-34],[130,-32],[115,-34]],
        [[-5,50],[1,51],[-2,56],[-5,58],[-6,55]],
        [[130,32],[135,34],[140,35],[142,39],[141,45],[145,44],[141,41],[139,38],[135,36]],
        [[95,5],[98,4],[106,-3],[105,-6],[101,-3]],
        [[109,2],[117,7],[119,1],[116,-4],[110,-3]],
        [[172,-34],[178,-38],[174,-41],[172,-41]],
        [[44,-25],[50,-15],[49,-12],[44,-17]],
        [[-24,64],[-14,65],[-14,64],[-22,63]],
        [[-180,-90],[-180,-70],[-60,-65],[0,-70],[100,-66],[180,-70],[180,-90]]
    ];

    const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

    const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const theme = () => document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const magVar = (m) => m >= 6 ? '--mag-high' : (m >= 4 ? '--mag-med' : '--mag-low');
    const magCls = (m) => m >= 6 ? 'mag-high' : (m >= 4 ? 'mag-med' : 'mag-low');
    const toDate = (s) => new Date(String(s).replace(' ', 'T') + 'Z');
    const fmtDate = (s) => toDate(s).toLocaleString(EW.lang === 'en' ? 'en-GB' : 'fr-FR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }) + ' UTC';

    function el(tag, attrs, children) {
        const n = document.createElement(tag);
        for (const k in (attrs || {})) {
            if (k === 'text') n.textContent = attrs[k];
            else if (k === 'class') n.className = attrs[k];
            else n.setAttribute(k, attrs[k]);
        }
        (children || []).forEach(c => n.appendChild(c));
        return n;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.documentElement;
        const page = document.body.dataset.page;
        let onThemeChange = () => {};

        // --- Thème jour/nuit (dynamique, mémorisé) ---
        const themeBtn = document.getElementById('themeBtn');
        function syncThemeBtn() {
            if (!themeBtn) return;
            themeBtn.textContent = theme() === 'dark' ? themeBtn.dataset.labelLight : themeBtn.dataset.labelDark;
        }
        if (themeBtn) {
            syncThemeBtn();
            themeBtn.addEventListener('click', () => {
                const next = theme() === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                try { localStorage.setItem('theme', next); } catch (e) { /* stockage indisponible */ }
                syncThemeBtn();
                onThemeChange();
            });
        }

        // --- Menu tiroir mobile ---
        const burger = document.getElementById('burgerBtn');
        const nav = document.getElementById('mainNav');
        if (burger && nav) {
            burger.addEventListener('click', () => {
                burger.setAttribute('aria-expanded', String(nav.classList.toggle('open')));
            });
        }

        // --- Carte Leaflet (accueil et archives) : marqueurs, fiche, filtres ---
        const mapBox = document.getElementById('worldMap');
        const quakes = EW.quakes || [];
        let favorites = EW.favorites || [];
        let favIds = EW.favIds || favorites.map(f => f.id);
        let refreshMap = () => {};
        let selectQuake = () => {};

        if (mapBox && window.L) {
            let minMag = 0, onlyFavs = false, selectedId = null, current = [];
            const markers = new Map();
            const minSel = document.getElementById('minmag');         // accueil uniquement
            const favFilter = document.getElementById('toggleFavFilter');
            const statsBox = document.getElementById('stats');
            const topList = document.getElementById('topList');
            const detail = document.getElementById('quakeDetail');
            if (minSel) minMag = parseFloat(minSel.value) || 0;

            const map = L.map(mapBox, { minZoom: 2, maxZoom: 12, worldCopyJump: true, zoomSnap: 0.5 }).setView([20, 10], 2);

            // Contours de secours : ajoutés seulement si les tuiles ne se chargent pas (hors ligne)
            let landDrawn = false;
            function drawLand() {
                if (landDrawn) return;
                landDrawn = true;
                map.createPane('land').style.zIndex = 190;
                LAND.forEach(poly => L.polygon(poly.map(([lo, la]) => [la, lo]), {
                    pane: 'land', className: 'map-land', interactive: false, stroke: false
                }).addTo(map));
            }
            // Le thème sombre est obtenu par un filtre CSS sur les tuiles (voir style.css)
            L.tileLayer(TILES, { attribution: ATTRIBUTION, maxZoom: 12 }).on('tileerror', drawLand).addTo(map);
            const layer = L.layerGroup().addTo(map);

            function renderStats() {
                if (!statsBox) return;
                statsBox.replaceChildren();
                const add = (label, value) => statsBox.append(el('div', {}, [el('dt', { text: label }), el('dd', { text: value })]));
                add(I18N.js_events, String(current.length));
                if (current.length) {
                    const top = current.reduce((a, b) => b.magnitude > a.magnitude ? b : a);
                    add(I18N.js_strongest, 'M ' + top.magnitude.toFixed(1));
                    add(I18N.js_latest, fmtDate(current[0].date));
                }
            }

            function renderTop() {
                if (!topList) return;
                topList.replaceChildren();
                if (!current.length) { topList.append(el('li', { class: 'muted', text: I18N.js_none })); return; }
                current.slice().sort((a, b) => b.magnitude - a.magnitude).slice(0, 5).forEach(q => {
                    const b = el('button', { type: 'button', class: 'top-item' }, [
                        el('span', { class: 'badge-mag ' + magCls(q.magnitude), text: 'M ' + q.magnitude.toFixed(1) }),
                        el('span', { class: 'top-place', text: q.lieu }),
                        el('span', { class: 'top-date muted', text: fmtDate(q.date) })
                    ]);
                    b.addEventListener('click', () => { select(q.id, true); detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
                    topList.append(el('li', {}, [b]));
                });
            }

            function renderDetail(q) {
                detail.replaceChildren();
                if (!q) { detail.append(el('p', { class: 'muted', text: I18N.js_hint })); return; }
                const isFav = favIds.includes(q.id);
                const actions = el('div', { class: 'detail-actions' });
                if (EW.logged) {
                    actions.append(el('button', {
                        type: 'button', class: 'btn-fav btn-fav-action' + (isFav ? ' active' : ''), text: isFav ? I18N.fav_exists : I18N.btn_add_fav,
                        'data-id': q.id, 'data-lieu': q.lieu, 'data-mag': q.magnitude, 'data-date': q.date,
                        'data-lat': q.lat, 'data-lon': q.lon, 'data-csrf': EW.csrf
                    }));
                } else {
                    actions.append(el('a', { href: 'compte.php', text: I18N.js_login_to_fav }));
                }
                if (!/^demo_/.test(q.id)) {
                    actions.append(el('a', { href: 'https://earthquake.usgs.gov/earthquakes/eventpage/' + encodeURIComponent(q.id), target: '_blank', rel: 'noopener', text: I18N.js_usgs }));
                }
                detail.append(
                    el('div', { class: 'detail-mag ' + magCls(q.magnitude) }, [el('span', { text: 'M' }), el('strong', { text: q.magnitude.toFixed(1) })]),
                    el('div', { class: 'detail-body' }, [
                        el('h2', { text: q.lieu }),
                        el('p', { class: 'muted', text: fmtDate(q.date) + ' — ' + q.lat.toFixed(2) + '°, ' + q.lon.toFixed(2) + '°' }),
                        actions
                    ])
                );
            }

            function styleFor(q, selected) {
                return {
                    radius: Math.max(5, Math.min(22, (q.magnitude - 1.5) * 3)),
                    fillColor: cssVar(magVar(q.magnitude)), fillOpacity: selected ? 0.95 : 0.7,
                    color: selected ? cssVar('--ink') : '#ffffff', weight: selected ? 3 : 1
                };
            }

            function select(id, fly) {
                const prev = markers.get(selectedId);
                if (prev) prev.marker.setStyle(styleFor(prev.q, false));
                selectedId = id;
                const cur = markers.get(id);
                const q = cur ? cur.q : ([...quakes, ...favorites].find(x => x.id === id) || null);
                if (cur) {
                    cur.marker.setStyle(styleFor(q, true));
                    cur.marker.bringToFront();
                    if (fly) map.flyTo([q.lat, q.lon], Math.max(map.getZoom(), 4), { duration: 0.8 });
                    else map.panTo([q.lat, q.lon]);
                }
                renderDetail(q);
                document.querySelectorAll('tr.seisme-row').forEach(r => r.classList.toggle('selected', r.dataset.id === id));
            }
            selectQuake = select;

            refreshMap = function () {
                // Avec « Uniquement mes favoris », on affiche tous les favoris enregistrés (pas seulement ceux du flux)
                const source = onlyFavs ? favorites : quakes;
                current = source.filter(q => q.magnitude >= minMag).sort((a, b) => b.date.localeCompare(a.date));
                layer.clearLayers();
                markers.clear();
                current.slice().sort((a, b) => a.magnitude - b.magnitude).forEach(q => {
                    const m = L.circleMarker([q.lat, q.lon], styleFor(q, q.id === selectedId)).addTo(layer);
                    m.on('click', () => select(q.id, false));
                    const node = m.getElement();
                    if (node) {
                        node.setAttribute('tabindex', '0');
                        node.setAttribute('role', 'button');
                        node.setAttribute('aria-label', 'M ' + q.magnitude.toFixed(1) + ' - ' + q.lieu);
                        node.addEventListener('keydown', (ev) => {
                            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); select(q.id, false); }
                        });
                    }
                    markers.set(q.id, { marker: m, q });
                });
                if (selectedId && !markers.has(selectedId)) { selectedId = null; renderDetail(null); }
                renderStats();
                renderTop();
            };

            onThemeChange = () => refreshMap();

            if (minSel) {
                minSel.addEventListener('change', () => { minMag = parseFloat(minSel.value) || 0; refreshMap(); });
            }
            if (favFilter) {
                favFilter.addEventListener('click', () => {
                    onlyFavs = !onlyFavs;
                    favFilter.classList.toggle('active', onlyFavs);
                    favFilter.setAttribute('aria-pressed', String(onlyFavs));
                    favFilter.textContent = onlyFavs ? I18N.filter_all : I18N.filter_only_favs;
                    refreshMap();
                });
            }
            refreshMap();
            // Ajuste la vue : toujours sur les archives, seulement s'il y en a peu à l'accueil (mode hors ligne)
            if (current.length && (page === 'archive' || current.length < 30)) {
                map.fitBounds(L.latLngBounds(current.map(q => [q.lat, q.lon])).pad(0.2), { maxZoom: 5 });
            }
        }

        // Bouton « voir sur la carte » des tableaux (archives)
        document.addEventListener('click', (e) => {
            const b = e.target.closest('.btn-show');
            if (!b || !mapBox) return;
            selectQuake(b.dataset.id, true);
            mapBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        // --- Ajout / retrait de favoris (AJAX POST + CSRF) ---
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-fav-action');
            if (!btn) return;
            const d = btn.dataset;
            btn.disabled = true;
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf: d.csrf, action: 'toggle_fav', seisme_id: d.id,
                        lieu: d.lieu, magnitude: d.mag, date: d.date, lat: d.lat, lon: d.lon
                    })
                });
                const data = await res.json();
                if (!res.ok) { alert(data.error || I18N.js_err_update); return; }

                const added = data.status === 'added';
                document.querySelectorAll('.btn-fav-action').forEach(b => {
                    if (b.dataset.id !== d.id) return;
                    b.classList.toggle('active', added);
                    b.textContent = added ? I18N.fav_exists : I18N.btn_add_fav;
                });
                favIds = added ? favIds.concat(d.id) : favIds.filter(x => x !== d.id);
                favorites = added
                    ? favorites.concat([{ id: d.id, lieu: d.lieu, magnitude: parseFloat(d.mag), date: d.date, lat: parseFloat(d.lat), lon: parseFloat(d.lon) }])
                    : favorites.filter(x => x.id !== d.id);

                if (page === 'compte' && !added) {
                    const tbody = btn.closest('tbody');
                    btn.closest('tr')?.remove();
                    const c = document.getElementById('favCount');
                    if (c) c.textContent = Math.max(0, Number(c.textContent) - 1);
                    if (tbody && !tbody.children.length) {
                        tbody.append(el('tr', {}, [el('td', { colspan: '4', class: 'muted center', text: I18N.no_favs_msg })]));
                    }
                } else {
                    refreshMap();
                }
            } catch (err) {
                alert(I18N.js_err_server);
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
