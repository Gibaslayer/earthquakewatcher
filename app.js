/**
 * app.js - Browser logic of EarthquakeWatcher.
 *
 * It does three simple things:
 *   1. opens / closes the menu on mobile,
 *   2. draws the earthquakes on a Leaflet map (home page) and shows the clicked one below the map,
 *   3. sends "add / remove favourite" requests to api.php with fetch().
 *
 * The data comes from PHP: pageFooter() in core.php creates the global object
 * window.EW = { quakes, favorites, favIds, i18n, logged, lang }.
 */

const DATA = window.EW || {};
const TEXT = DATA.i18n || {};

// ---------------------------------------------------------------- helpers

/**
 * Returns the colour of a marker according to the magnitude.
 *
 * @param {number} mag Earthquake magnitude.
 * @returns {string} A CSS colour (green, orange or red).
 */
function colorOf(mag) {
    if (mag >= 6) return '#ea614f';
    if (mag >= 4) return '#d79c35';
    return '#6fb27d';
}

/**
 * Returns the CSS class of a magnitude (used for the big number in the detail box).
 *
 * @param {number} mag Earthquake magnitude.
 * @returns {string} 'mag-low', 'mag-med' or 'mag-high'.
 */
function classOf(mag) {
    if (mag >= 6) return 'mag-high';
    if (mag >= 4) return 'mag-med';
    return 'mag-low';
}

// ---------------------------------------------------------------- favourites

/**
 * Asks api.php to add the earthquake to the favourites, or to remove it if it is already one.
 * See api.php for the exact format of the request and of the answer.
 *
 * @param {Object} quake Earthquake: { id, lieu, magnitude, date, lat, lon }.
 * @returns {Promise<string|null>} 'added', 'removed', or null if something went wrong.
 */
async function toggleFavourite(quake) {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'toggle_fav',
                seisme_id: quake.id,
                lieu: quake.lieu,
                magnitude: quake.magnitude,
                date: quake.date,
                lat: quake.lat,
                lon: quake.lon
            })
        });
        const answer = await response.json();
        if (!response.ok) {
            alert(answer.error || TEXT.js_err_update);
            return null;
        }
        return answer.status;
    } catch (error) {
        alert(TEXT.js_err_server);
        return null;
    }
}

// ---------------------------------------------------------------- home page: the map

/**
 * Builds the map, the filters and the detail box of the home page.
 */
function setupMap() {
    const quakes = DATA.quakes || [];
    const favIds = DATA.favIds || [];          // ids of the favourites of the signed-in user
    let selected = null;                     // earthquake shown in the detail box

    // The page elements we need.
    const minMagSelect = document.getElementById('minmag');
    const favOnlyButton = document.getElementById('toggleFavFilter'); // only for signed-in users
    const countBox = document.getElementById('count');
    const hint = document.getElementById('detailHint');
    const detail = document.getElementById('quakeDetail');
    const favButton = document.getElementById('favBtn');
    const loginLink = document.getElementById('loginLink');

    // The Leaflet map with the OpenStreetMap background tiles.
    const map = L.map('worldMap', { minZoom: 2, maxZoom: 12 }).setView([20, 10], 2);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    const markers = L.layerGroup().addTo(map);

    /**
     * Fills the detail box with one earthquake.
     *
     * @param {Object} quake The earthquake that was clicked.
     */
    function showDetail(quake) {
        selected = quake;
        hint.hidden = true;
        detail.hidden = false;

        document.getElementById('dMagBox').className = 'detail-mag ' + classOf(quake.magnitude);
        document.getElementById('dMag').textContent = quake.magnitude.toFixed(1);
        document.getElementById('dPlace').textContent = quake.lieu;
        document.getElementById('dInfo').textContent =
            quake.date + ' UTC | ' + quake.lat.toFixed(2) + ', ' + quake.lon.toFixed(2);

        // Signed-in users get the favourite button, the others a link to the login page.
        favButton.hidden = !DATA.logged;
        loginLink.hidden = DATA.logged;
        updateFavButton();
    }

    /**
     * Sets the text of the favourite button: "add" or "already a favourite".
     */
    function updateFavButton() {
        const isFav = selected && favIds.includes(selected.id);
        favButton.classList.toggle('active', isFav);
        favButton.textContent = isFav ? TEXT.fav_exists : TEXT.btn_add_fav;
    }

    /**
     * Redraws the markers according to the two filters (minimum magnitude, favourites only).
     */
    function drawMarkers() {
        markers.clearLayers();
        const minMag = parseFloat(minMagSelect.value);
        const onlyFavs = favOnlyButton && favOnlyButton.classList.contains('active');
        let count = 0;

        quakes.forEach(function (quake) {
            if (quake.magnitude < minMag) return;
            if (onlyFavs && !favIds.includes(quake.id)) return;

            // One circle per earthquake: the bigger the magnitude, the bigger the circle.
            const circle = L.circleMarker([quake.lat, quake.lon], {
                radius: Math.max(5, (quake.magnitude - 1.5) * 3),
                color: '#ffffff',
                weight: 1,
                fillColor: colorOf(quake.magnitude),
                fillOpacity: 0.7
            }).addTo(markers);
            circle.on('click', function () { showDetail(quake); });
            count++;
        });
        countBox.textContent = count + ' ' + TEXT.js_events;
    }

    // Filters.
    minMagSelect.addEventListener('change', drawMarkers);
    if (favOnlyButton) {
        favOnlyButton.addEventListener('click', function () {
            favOnlyButton.classList.toggle('active');
            favOnlyButton.textContent = favOnlyButton.classList.contains('active')
                ? TEXT.filter_all : TEXT.filter_only_favs;
            drawMarkers();
        });
    }

    // Click on the favourite button of the detail box.
    favButton.addEventListener('click', async function () {
        const result = await toggleFavourite(selected);
        if (result === 'added') favIds.push(selected.id);
        if (result === 'removed') favIds.splice(favIds.indexOf(selected.id), 1);
        updateFavButton();
        drawMarkers();
    });

    drawMarkers();
}

// ---------------------------------------------------------------- account page

/**
 * Makes the "remove from favourites" buttons of the account page work.
 * After the request succeeds, the page is reloaded to show the updated list.
 */
function setupFavouriteList() {
    document.querySelectorAll('.btn-fav-action').forEach(function (button) {
        button.addEventListener('click', async function () {
            const result = await toggleFavourite({
                id: button.dataset.id, lieu: '', magnitude: 0, date: '', lat: 0, lon: 0
            });
            if (result) location.reload();
        });
    });
}

// ---------------------------------------------------------------- start

document.addEventListener('DOMContentLoaded', function () {
    // Burger menu on mobile.
    const burger = document.getElementById('burgerBtn');
    const nav = document.getElementById('mainNav');
    if (burger && nav) {
        burger.addEventListener('click', function () {
            nav.classList.toggle('open');
            burger.setAttribute('aria-expanded', nav.classList.contains('open'));
        });
    }

    if (document.getElementById('worldMap')) setupMap();
    setupFavouriteList();
});
