(() => {
    'use strict';
    function create(root) {
        if (!root || !window.L) throw new Error('Die Karte konnte nicht initialisiert werden.');
        const map = L.map(root), layer = L.layerGroup().addTo(map);
        if (document.querySelector('meta[name="crosschapp-test-mode"]')?.content !== '1') L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
        return {
            clear() { layer.clearLayers(); },
            marker(point, content, label = '') { const marker = L.marker(point).bindPopup(content).addTo(layer); if (label) marker.bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -12] }); return marker; },
            start(point, content) { return L.circleMarker(point, { radius: 9, color: '#202124', weight: 3, fillColor: '#fff', fillOpacity: 1 }).bindPopup(content).addTo(layer); },
            route(coordinates) { return L.polyline(coordinates.map(point => [point[1], point[0]]), { color: '#cf2030', weight: 5, opacity: .8 }).addTo(layer); },
            fit(points) { window.setTimeout(() => { map.invalidateSize(); points.length < 2 ? map.setView(points[0], 12) : map.fitBounds(points, { padding: [35, 35], maxZoom: 13 }); }, 0); },
            invalidate() { window.setTimeout(() => map.invalidateSize(), 0); },
        };
    }
    window.CrossChappMap = { create };
})();
