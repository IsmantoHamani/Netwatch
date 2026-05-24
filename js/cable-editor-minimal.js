// ========== CABLE EDITOR SYSTEM - REAL TIME CABLE EDITING ==========
let cableEditMode = false;
let cableEditingApId = null;
let waypointMarkers = {};
let cablePolyline = null;
let tempWaypoints = [];

// Parse waypoints dari comment netwatch
function parseWaypointsFromComment(comment) {
  if (!comment) return null;
  try {
    const match = comment.match(/waypoints:\s*\[([\d\.,\s\-]+)\]/);
    if (match) {
      const coords = match[1].split('],[').map(c => {
        const parts = c.replace(/[\[\]]/g, '').split(',').map(p => parseFloat(p.trim()));
        return parts.length === 2 ? [parts[0], parts[1]] : null;
      }).filter(c => c);
      return coords.length > 0 ? coords : null;
    }
  } catch (e) {
    console.warn('Parse waypoints error:', e);
  }
  return null;
}

// Format waypoints ke comment netwatch
function formatWaypointsComment(waypoints) {
  if (!waypoints || waypoints.length === 0) return '';
  const coords = waypoints.map(w => `[${w.lat},${w.lng}]`).join(',');
  return `waypoints: [${coords}]`;
}

// Mulai edit kabel
function startEditCable(apId) {
  if (!lines[apId]) {
    showToast('Kabel tidak ditemukan', 3000);
    return;
  }
  
  cableEditMode = true;
  cableEditingApId = apId;
  
  const ap = AP_LIST.find(a => a.id === apId);
  
  // Cek waypoints dari Netwatch comment atau dari data AP
  let savedWaypoints = null;
  if (ap && ap.line) {
    const parentAp = AP_LIST.find(p => p.id === ap.line);
    if (parentAp && parentAp.comment) {
      savedWaypoints = parseWaypointsFromComment(parentAp.comment);
    }
  }
  
  if (savedWaypoints && savedWaypoints.length > 0) {
    tempWaypoints = savedWaypoints.map(w => L.latLng(w[0], w[1]));
  } else {
    const currentLatlngs = lines[apId].getLatLngs();
    tempWaypoints = Array.isArray(currentLatlngs) ? currentLatlngs.slice() : [currentLatlngs];
  }
  
  renderWaypointMarkers();
  
  if (lines[apId]) {
    lines[apId].setStyle({ weight: 3, opacity: 1, color: '#ff00ff', dashArray: '5, 5' });
  }
  
  showToast('Mode Edit Kabel - Drag untuk ubah, klik untuk tambah titik', 3000);
}

// Render waypoint markers
function renderWaypointMarkers() {
  Object.values(waypointMarkers).forEach(m => {
    if (map.hasLayer(m)) map.removeLayer(m);
  });
  waypointMarkers = {};
  
  tempWaypoints.forEach((latlng, idx) => {
    const icon = L.divIcon({
      className: 'cable-waypoint-icon',
      html: `<div style="
        width:20px; height:20px; 
        background:#ffff00; border:2px solid #ff00ff;
        border-radius:50%; 
        display:flex; align-items:center; justify-content:center;
        color:#000; font-size:10px; font-weight:bold;
        cursor: pointer;
      ">${idx + 1}</div>`,
      iconSize: [20, 20],
      iconAnchor: [10, 10]
    });
    
    const marker = L.marker(latlng, { icon, draggable: true }).addTo(map);
    
    marker.on('drag', function (e) {
      const newLatlng = e.target.getLatLng();
      tempWaypoints[idx] = newLatlng;
      updateCablePath();
    });
    
    marker.on('contextmenu', function (e) {
      L.DomEvent.stop(e);
      if (tempWaypoints.length > 2) {
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
  if (cablePolyline && map.hasLayer(cablePolyline)) {
    map.removeLayer(cablePolyline);
  }
  
  if (tempWaypoints.length >= 2) {
    cablePolyline = L.polyline(tempWaypoints, {
      color: '#ff00ff',
      weight: 3,
      opacity: 0.9,
      dashArray: '5, 5',
      interactive: false
    }).addTo(map);
  }
}

// Handle klik peta untuk tambah waypoint
function handleMapClickForCableEdit(latlng) {
  if (!cableEditMode) return;
  
  tempWaypoints.push(latlng);
  renderWaypointMarkers();
  updateCablePath();
  showToast(`Titik ${tempWaypoints.length} ditambahkan (klik Simpan untuk lanjut)`, 2000);
}

// Simpan kabel ke Netwatch comment
async function saveCableWaypoints() {
  if (!cableEditingApId || tempWaypoints.length < 2) {
    showToast('Harus minimal 2 titik', 3000);
    return;
  }
  
  try {
    const ap = AP_LIST.find(a => a.id === cableEditingApId);
    if (!ap || !ap.line) {
      showToast('AP atau parent tidak ditemukan', 3000);
      return;
    }
    
    const parentAp = AP_LIST.find(p => p.id === ap.line);
    if (!parentAp) {
      showToast('Parent AP tidak ditemukan', 3000);
      return;
    }
    
    const waypointComment = formatWaypointsComment(tempWaypoints);
    const newComment = `${parentAp.comment || ''}\n${waypointComment}`.trim();
    
    // Update Netwatch comment
    const resp = await fetch('update_netwatch_comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        apId: parentAp.id,
        comment: newComment
      })
    });
    
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const j = await resp.json();
    
    if (j.success) {
      parentAp.comment = newComment;
      showToast('✅ Kabel berhasil disimpan ke Netwatch!', 3000);
      cancelEditCable();
      
      const meta = linesMeta[cableEditingApId];
      if (meta) {
        const from = getMarkerForReference(meta.fromRef);
        const to = markers[meta.toId];
        if (from && to) {
          const newLatlngs = tempWaypoints.map(w => L.latLng(w.lat, w.lng));
          lines[cableEditingApId].setLatLngs(newLatlngs);
          createDecoratorForLine(cableEditingApId, lines[cableEditingApId], lineColors[cableEditingApId]);
        }
      }
    } else {
      showToast('❌ Gagal simpan: ' + (j.error || 'unknown'), 3000);
    }
  } catch (err) {
    showToast('❌ Error: ' + err.message, 4000);
  }
}

// Batalkan edit
function cancelEditCable() {
  cableEditMode = false;
  cableEditingApId = null;
  tempWaypoints = [];
  
  Object.values(waypointMarkers).forEach(m => {
    if (map.hasLayer(m)) map.removeLayer(m);
  });
  waypointMarkers = {};
  
  if (cablePolyline && map.hasLayer(cablePolyline)) {
    map.removeLayer(cablePolyline);
  }
  cablePolyline = null;
  
  if (cableEditingApId && lines[cableEditingApId]) {
    const ap = AP_LIST.find(a => a.id === cableEditingApId);
    const color = ap ? normalizeColor(ap.lineColor || 'lime') : 'lime';
    lines[cableEditingApId].setStyle({ weight: 2, opacity: 0.8, color: color, dashArray: '' });
  }
  
  showToast('Edit kabel dibatalkan', 2000);
}

// Setup map click handler
map.on('click', function (e) {
  if (cableEditMode) {
    handleMapClickForCableEdit(e.latlng);
  }
});
