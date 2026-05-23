// ========== CABLE EDITOR SYSTEM ==========
let cableEditMode = false;
let cableEditingApId = null;
let waypointMarkers = {};
let cablePolyline = null;
let tempWaypoints = [];

// Fungsi untuk masuk mode edit kabel
function startEditCable(apId) {
    if(!lines[apId]) {
        showToast('Kabel tidak ditemukan', 3000);
        return;
    }
    
    cableEditMode = true;
    cableEditingApId = apId;
    
    // Ambil waypoints dari AP atau buat dari polyline saat ini
    const ap = AP_LIST.find(a => a.id === apId);
    
    if(ap && ap.waypoints && Array.isArray(ap.waypoints) && ap.waypoints.length > 0) {
        tempWaypoints = ap.waypoints.map(w => L.latLng(w[0], w[1]));
    } else {
        // Gunakan koordinat dari polyline saat ini
        const currentLatlngs = lines[apId].getLatLngs();
        tempWaypoints = Array.isArray(currentLatlngs) ? currentLatlngs.slice() : [currentLatlngs];
    }
    
    // Tampilkan waypoint markers
    renderWaypointMarkers();
    
    // Ubah tampilan kabel
    lines[apId].setStyle({ weight: 3, opacity: 1, color: '#ff0000', dashArray: '5, 5' });
    
    showToast('Mode Edit Kabel - Drag waypoint atau klik peta untuk tambah titik', 3000);
    updateCableEditUI();
}

// Render waypoint markers
function renderWaypointMarkers() {
    // Hapus marker lama
    Object.values(waypointMarkers).forEach(m => {
        if(map.hasLayer(m)) map.removeLayer(m);
    });
    waypointMarkers = {};
    
    // Buat marker baru
    tempWaypoints.forEach((latlng, idx) => {
        const icon = L.divIcon({
            className: 'cable-waypoint-icon',
            html: `<div style="
                width:20px; height:20px; 
                background:#00ffff; border:2px solid #0099ff;
                border-radius:50%; 
                display:flex; align-items:center; justify-content:center;
                color:#000; font-size:10px; font-weight:bold;
            ">${idx + 1}</div>`,
            iconSize: [20, 20],
            iconAnchor: [10, 10]
        });
        
        const marker = L.marker(latlng, { icon, draggable: true }).addTo(map);
        
        // Drag events
        marker.on('drag', function(e) {
            const newLatlng = e.target.getLatLng();
            tempWaypoints[idx] = newLatlng;
            updateCablePath();
        });
        
        // Right-click untuk hapus waypoint
        marker.on('contextmenu', function(e) {
            if(tempWaypoints.length > 2) {
                tempWaypoints.splice(idx, 1);
                renderWaypointMarkers();
                updateCablePath();
                showToast(`Titik ${idx + 1} dihapus`, 2000);
            } else {
                showToast('Minimal harus ada 2 titik', 2000);
            }
        });
        
        waypointMarkers[idx] = marker;
    });
}

// Update tampilan kabel saat edit
function updateCablePath() {
    if(cablePolyline && map.hasLayer(cablePolyline)) {
        map.removeLayer(cablePolyline);
    }
    
    if(tempWaypoints.length >= 2) {
        cablePolyline = L.polyline(tempWaypoints, {
            color: '#ff0000',
            weight: 3,
            opacity: 0.8,
            dashArray: '5, 5',
            interactive: false
        }).addTo(map);
    }
}

// Klik peta untuk tambah waypoint
function handleMapClickForCableEdit(latlng) {
    if(!cableEditMode) return;
    
    tempWaypoints.push(latlng);
    renderWaypointMarkers();
    updateCablePath();
    showToast(`Titik ${tempWaypoints.length} ditambahkan`, 2000);
}

// Simpan kabel
async function saveCableWaypoints() {
    if(!cableEditingApId || tempWaypoints.length < 2) {
        showToast('Harus minimal 2 titik', 3000);
        return;
    }
    
    try {
        const resp = await fetch('save_line_waypoints.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                apId: cableEditingApId,
                waypoints: tempWaypoints.map(w => [w.lat, w.lng])
            })
        });
        
        if(!resp.ok) throw new Error('HTTP ' + resp.status);
        const j = await resp.json();
        
        if(j.success) {
            // Update AP data
            const ap = AP_LIST.find(a => a.id === cableEditingApId);
            if(ap) ap.waypoints = tempWaypoints.map(w => [w.lat, w.lng]);
            
            showToast('✅ Kabel berhasil disimpan!', 3000);
            cancelEditCable();
        } else {
            showToast('❌ Gagal simpan: ' + (j.error || 'unknown'), 3000);
        }
    } catch(err) {
        showToast('❌ Error: ' + err.message, 4000);
    }
}

// Batalkan edit
function cancelEditCable() {
    cableEditMode = false;
    cableEditingApId = null;
    tempWaypoints = [];
    
    // Hapus waypoint markers
    Object.values(waypointMarkers).forEach(m => {
        if(map.hasLayer(m)) map.removeLayer(m);
    });
    waypointMarkers = {};
    
    // Hapus polyline sementara
    if(cablePolyline && map.hasLayer(cablePolyline)) {
        map.removeLayer(cablePolyline);
    }
    cablePolyline = null;
    
    // Restore garis asli
    if(lines[cableEditingApId]) {
        const ap = AP_LIST.find(a => a.id === cableEditingApId);
        const color = ap ? normalizeColor(ap.lineColor || 'lime') : 'lime';
        lines[cableEditingApId].setStyle({ weight: 2, opacity: 0.8, color: color, dashArray: '' });
    }
    
    updateCableEditUI();
    showToast('Edit kabel dibatalkan', 2000);
}

// Update UI tombol edit
function updateCableEditUI() {
    const editBtn = document.getElementById('cable-edit-btn');
    const saveBtn = document.getElementById('cable-save-btn');
    const cancelBtn = document.getElementById('cable-cancel-btn');
    
    if(!editBtn) return;
    
    if(cableEditMode) {
        editBtn.style.display = 'none';
        saveBtn.style.display = 'inline-block';
        cancelBtn.style.display = 'inline-block';
    } else {
        editBtn.style.display = 'inline-block';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
    }
}

// Setup event listener untuk map click
map.on('click', function(e) {
    if(cableEditMode) {
        handleMapClickForCableEdit(e.latlng);
    }
});
