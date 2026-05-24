<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

$CACHE_TTL = ['netwatch'=>30,'odp'=>600,'hotspot'=>15];
$CACHE_DIR = __DIR__ . '/cache';
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0755, true);

session_start();
require 'routeros_api.class.php';

$deleteMessage = '';
$deleteStatus = 'success';
if(isset($_SESSION['delete_message'])){
    $deleteMessage = $_SESSION['delete_message'];
    $deleteStatus = $_SESSION['delete_status'] ?? 'success';
    unset($_SESSION['delete_message']);
    unset($_SESSION['delete_status']);
}

if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])) {
    header('Location: index.php');
    exit;
}

$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $mt_ip);
$dataFile = __DIR__ . "/ap_data_{$safe_ip}.json";
if (!file_exists($dataFile)) file_put_contents($dataFile, json_encode([]));

function getCacheFile($type,$ip){global $CACHE_DIR;return $CACHE_DIR.'/ap_'.$type.'_'.preg_replace('/[^a-zA-Z0-9]/','_',$ip).'.cache';}
function getFromCache($type,$ip){$file=getCacheFile($type,$ip);if(file_exists($file)&&time()-filemtime($file)<$GLOBALS['CACHE_TTL'][$type]){return json_decode(file_get_contents($file),true);}return null;}
function saveToCache($type,$ip,$data){$file=getCacheFile($type,$ip);file_put_contents($file,json_encode($data,JSON_UNESCAPED_UNICODE),LOCK_EX);}

$apList=json_decode(file_get_contents($dataFile),true)?:[];

$reloadMinutes=0;
if(file_exists(__DIR__.'/settings.json')){
    $settings=json_decode(file_get_contents(__DIR__.'/settings.json'),true);
    $reloadMinutes=intval($settings['reload_minutes']??0);
}

$netwatch=[];
$hotspotActive=[];

$API=new RouterosAPI();
if($API->connect($mt_ip,$_SESSION['user'],$_SESSION['pass'])){
    $nw=getFromCache('netwatch',$mt_ip);
    if(!$nw){
        $nw=$API->comm('/tool/netwatch/print');
        saveToCache('netwatch',$mt_ip,$nw);
    }
    foreach($nw as $entry){
        if(isset($entry['host'])&&isset($entry['disabled'])){
            $host=$entry['host'];
            $status=($entry['disabled']==0)?'up':'down';
            $netwatch[$host]=['status'=>$status,'since'=>$entry['comment']??'','lasttime'=>date('Y-m-d H:i:s')];
        }
    }
    
    $hs=getFromCache('hotspot',$mt_ip);
    if(!$hs){
        $hs=$API->comm('/ip/hotspot/active/print');
        saveToCache('hotspot',$mt_ip,$hs);
    }
    foreach($hs as $entry){
        if(isset($entry['user'])){
            $hotspotActive[]=['user'=>$entry['user'],'address'=>$entry['address']??''];
        }
    }
    $API->disconnect();
}

$savedApList=$apList;
$linesData=[];
foreach($apList as $ap){
    if(isset($ap['line'])&&!empty($ap['line'])){
        $parentToken=$ap['line'];
        $parentId=null;
        if(!empty($parentToken)&&$parentToken!==''&&strlen($parentToken)>0){
            if(strpos($parentToken,'ap_')===0){
                $parentId=$parentToken;
            }else{
                if(!is_null($savedApList)&&is_array($savedApList)){
                    foreach($savedApList as $p){
                        if(trim($p['name'])===$parentToken){
                            $parentId=$p['id'];
                            break;
                        }
                    }
                }
            }
        }
        $lat=(float)($ap['lat']??0);
        $lng=(float)($ap['lng']??0);
        if($lat>0&&$lng>0){
            $linesData[$ap['id']]=['toId'=>$ap['id'],'fromRef'=>$parentId??$parentToken,'latlngs'=>[[$lat,$lng]],'mid'=>[$lat,$lng],'straight'=>0,'color'=>$ap['lineColor']??'lime'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitoring AP - Netwatch</title>
<link rel="icon" href="favicon.png" />
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="styles.css">
<style>
.leaflet-popup-content { color: #000; }
.wifi-icon { width: 20px; height: 20px; display: inline-block; }
.wifi-icon.up { filter: hue-rotate(120deg); }
.wifi-icon.down { filter: hue-rotate(0deg) saturate(2); }
#map { width: 100%; height: 100vh; }
</style>
</head>
<body style="margin:0; padding:0;">
<div id="map"></div>
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylinedecorator.js"></script>
<script>
const AP_LIST =<?=json_encode($apList,JSON_UNESCAPED_UNICODE)?>;
const NW_STATUS =<?=json_encode($netwatch,JSON_UNESCAPED_UNICODE)?>;
const HOTSPOT_ACTIVE =<?=json_encode($hotspotActive,JSON_UNESCAPED_UNICODE)?>;
const LINES_JSON =<?=json_encode($linesData ??[],JSON_UNESCAPED_UNICODE)?>;
const MT_IP = '<?= addslashes($mt_ip) ?>';

const ACCEPTED_COLORS = ['lime','pink','blue','gray','green','gold','aqua','gainsboro','chartreuse','magenta','orange','fuchsia','black','yellow','brown'];

function getStatus(ip){ 
  if (!ip || ip === '') return 'unknown';
  return (NW_STATUS[ip] && NW_STATUS[ip].status) ? NW_STATUS[ip].status : 'unknown'; 
}

function getSince(ip){ 
  if (!ip || ip === '') return '';
  return (NW_STATUS[ip] && NW_STATUS[ip].since) ? NW_STATUS[ip].since : ''; 
}

function colorNameToHex(name){
  if(!name) return name;
  const m = {
    'lime':'#00ff00', 'pink':'#ff69b4', 'blue':'#007bff', 'gray':'#6b7280', 'green':'#008000',
    'gold':'#ffd700', 'aqua':'#00ffff', 'gainsboro':'#dcdcdc', 'chartreuse':'#7fff00',
    'magenta':'#ff00ff', 'orange':'#f59e0b', 'fuchsia':'#ff00ff', 'black':'#000000',
    'yellow':'#ffff00', 'brown':'#a0522d'
  };
  const s = String(name).trim().toLowerCase();
  return m[s] || name;
}

function expandHex3(h){
  return h.replace(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i, (m,r,g,b) => '#' + r+r + g+g + b+b);
}

function getTextColorFor(bg){
  if(!bg) return '#000';
  let hex = colorNameToHex(bg);
  if(/^#[0-9a-f]{3}$/i.test(hex)) hex = expandHex3(hex);
  if(/^#[0-9a-f]{6}$/i.test(hex)){
    const r = parseInt(hex.substr(1,2),16);
    const g = parseInt(hex.substr(3,2),16);
    const b = parseInt(hex.substr(5,2),16);
    const lum = 0.299*r + 0.587*g + 0.114*b;
    return lum > 186 ? '#000' : '#fff';
  }
  const darkNames = ['black','navy','purple','maroon','brown','darkblue','darkgreen'];
  if(darkNames.indexOf(String(bg).toLowerCase()) !== -1) return '#fff';
  return '#000';
}

const sat = L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', { maxZoom: 21, subdomains:['mt0','mt1','mt2','mt3'] });
const satDark = L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', { maxZoom: 21, subdomains:['mt0','mt1','mt2','mt3'], className: 'leaflet-tile-dark' });
const hybrid = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', { maxZoom: 21, subdomains:['mt0','mt1','mt2','mt3'] });

const map = L.map('map', { center:[0,118], zoom:4, layers:[sat], zoomControl:false });
L.control.zoom({ position: 'bottomright' }).addTo(map);
L.control.layers({ "Google Satellite": sat, "Google Satellite Dark": satDark, "Google Hybrid": hybrid }, null, { position:'topleft' }).addTo(map);

const markers={}; const markersByIp={}; const lines={}; const allLatLng=[];
const lineTooltips = {}; const decorators = {}; const lineColors = {}; const apNameLabels = {};
const linesMeta = {};

const AP_BY_ID = {};
AP_LIST.forEach(a => { AP_BY_ID[a.id] = a; });

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c])); }

function makeIconByType(status, type) {
  const cls = status === 'up' ? 'up' : (status === 'down' ? 'down' : 'unknown');
  if (type === 'odp') {
    return L.divIcon({
      className: '', html: `<div class="wifi-icon ${cls}" style="background: white;"><img src="icons/ODP.webp" class="wifi-img" style="width:14px;height:14px;"></div>`,
      iconSize: [15, 15], iconAnchor: [10, 10], popupAnchor: [0, -15]
    });
  } else {
    return L.divIcon({
      className: '', html: `<div class="wifi-icon ${cls}"><img src="icons/Wifi.webp" class="wifi-img"></div>`,
      iconSize: [15, 15], iconAnchor: [10, 10], popupAnchor: [0, -15]
    });
  }
}

function normalizeColor(c){
  if(!c) return 'lime';
  const s = String(c).trim();
  const lower = s.toLowerCase();
  if (ACCEPTED_COLORS.indexOf(lower) !== -1) return lower;
  if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s)) return s;
  return 'lime';
}

function getMarkerForReference(ref){
  if(!ref) return null;
  if(markers[ref]) return markers[ref];
  if(markersByIp[ref]) return markersByIp[ref];
  if(AP_BY_ID[ref] && markers[AP_BY_ID[ref].id]) return markers[AP_BY_ID[ref].id];
  const lower = String(ref).toLowerCase();
  for(const k in AP_BY_ID){
    if(String(AP_BY_ID[k].name).toLowerCase() === lower && markers[AP_BY_ID[k].id]) return markers[AP_BY_ID[k].id];
  }
  return null;
}

const canvasRenderer = L.canvas({ padding: 0.5 });

function isLatLngInView(latlng){
  try{ return map.getBounds().pad(0.25).contains(latlng); }catch(e){ return true; }
}

function isLineInView(first, last){
  try{
    const bounds = map.getBounds().pad(0.25);
    const box = L.latLngBounds([first, last]);
    return bounds.intersects(box) || bounds.contains(first) || bounds.contains(last);
  }catch(e){ return true; }
}

function debounce(fn, wait){
  let t;
  return function(...args){
    clearTimeout(t);
    t = setTimeout(()=> fn.apply(this, args), wait);
  };
}

function hashSignForId(id){
  let sum = 0;
  for(let i=0;i<id.length;i++) sum += id.charCodeAt(i);
  return (sum % 2 === 0) ? 1 : -1;
}

const EARTH_RADIUS = 6378137;
function toRad(deg){ return deg * Math.PI / 180; }

function haversineDistance(lat1, lon1, lat2, lon2){
  const φ1 = toRad(lat1), φ2 = toRad(lat2);
  const dφ = toRad(lat2 - lat1);
  const dλ = toRad(lon2 - lon1);
  const a = Math.sin(dφ/2)*Math.sin(dφ/2) + Math.cos(φ1)*Math.cos(φ2)*Math.sin(dλ/2)*Math.sin(dλ/2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return EARTH_RADIUS * c;
}

function flattenLatLngs(raw){
  if (Array.isArray(raw) && Array.isArray(raw[0]) && raw[0][0] && raw[0][0].lat !== undefined) return raw[0];
  return raw;
}

function polylineTotalLength(latlngs){
  if(!latlngs || latlngs.length < 2) return 0;
  let sum = 0;
  for(let i=1;i<latlngs.length;i++){
    sum += haversineDistance(latlngs[i-1].lat, latlngs[i-1].lng, latlngs[i].lat, latlngs[i].lng);
  }
  return sum;
}

function interpolateLatLng(a, b, t){
  return L.latLng(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t);
}

function pointAtDistanceAlong(latlngs, dist){
  if(!latlngs || latlngs.length === 0) return null;
  if(dist <= 0) return latlngs[0];
  let acc = 0;
  for(let i=1;i<latlngs.length;i++){
    const seg = haversineDistance(latlngs[i-1].lat, latlngs[i-1].lng, latlngs[i].lat, latlngs[i].lng);
    if(acc + seg >= dist){
      const remain = dist - acc;
      const t = seg <= 0 ? 0 : (remain / seg);
      return interpolateLatLng(latlngs[i-1], latlngs[i], t);
    }
    acc += seg;
  }
  return latlngs[latlngs.length-1];
}

function latLngToMercator(lat, lng){
  const x = EARTH_RADIUS * toRad(lng);
  const y = EARTH_RADIUS * Math.log(Math.tan(Math.PI/4 + toRad(lat)/2));
  return L.point(x, y);
}

function mercatorToLatLng(pt){
  const lng = (pt.x / EARTH_RADIUS) * 180 / Math.PI;
  const lat = (2 * Math.atan(Math.exp(pt.y / EARTH_RADIUS)) - Math.PI/2) * 180 / Math.PI;
  return L.latLng(lat, lng);
}

const SIMPLE_LINES_THRESHOLD = 200;
const ZOOM_THRESHOLD_FOR_DETAILS = 18;
const maxDecorators = 30;
const maxTooltips = 200;

const simplifiedMode = (AP_LIST && AP_LIST.length >= SIMPLE_LINES_THRESHOLD);

function buildBentLatLngs(fromLatLng, toLatLng, id, useWaypoints=true){
  try {
    const ap = AP_BY_ID[id];
    if(useWaypoints && ap && ap.waypoints && Array.isArray(ap.waypoints) && ap.waypoints.length > 2) {
      return ap.waypoints.map(w => L.latLng(w[0], w[1]));
    }
    
    const straightFallback = [fromLatLng, toLatLng];
    if (simplifiedMode) return straightFallback;

    const p1 = latLngToMercator(fromLatLng.lat, fromLatLng.lng);
    const p2 = latLngToMercator(toLatLng.lat, toLatLng.lng);

    const dx = p2.x - p1.x, dy = p2.y - p1.y;
    const segM = Math.sqrt(dx*dx + dy*dy);

    if (segM < 80) return straightFallback;

    const mid = L.point((p1.x + p2.x) / 2, (p1.y + p2.y) / 2);

    let nx = -dy, ny = dx;
    const norm = Math.sqrt(nx*nx + ny*ny) || 1; nx /= norm; ny /= norm;

    const offsetM = Math.min(Math.max(8, segM * 0.055), 140);
    const sign = hashSignForId(id);
    const control = L.point(mid.x + nx * offsetM * sign, mid.y + ny * offsetM * sign);

    const N = Math.min(12, Math.max(4, Math.round(segM / 300)));
    const latlngs = [];
    for (let i = 0; i <= N; i++) {
      const t = i / N;
      const x = (1 - t)*(1 - t)*p1.x + 2*(1 - t)*t*control.x + t*t*p2.x;
      const y = (1 - t)*(1 - t)*p1.y + 2*(1 - t)*t*control.y + t*t*p2.y;
      latlngs.push(mercatorToLatLng(L.point(x, y)));
    }
    return latlngs;
  } catch (err) {
    return [fromLatLng, toLatLng];
  }
}

function createPolylineFor(id, fromLatLng, toLatLng, options, useWaypoints=true){
  const zoom = map.getZoom ? map.getZoom() : ZOOM_THRESHOLD_FOR_DETAILS;
  
  const latlngs = buildBentLatLngs(fromLatLng, toLatLng, id, useWaypoints);
  
  const weight = zoom >= 16 ? 2 : (zoom >= 12 ? 1.5 : 1);
  const smoothFactor = zoom < 12 ? 3 : (zoom < 16 ? 2 : 1);
  
  const opts = Object.assign({ 
    renderer: canvasRenderer,
    color: options.color || '#00ff00', 
    weight: weight,
    opacity: 0.8, 
    interactive: false, 
    smoothFactor: smoothFactor
  }, options||{});
  
  return L.polyline(latlngs, opts);
}

function roundDistance(d){
  if(typeof d !== 'number' || isNaN(d)) return 0;
  return Math.round(d);
}

function removeDecorator(id){
  if(!decorators[id]) return;
  try { if(map.hasLayer(decorators[id])) map.removeLayer(decorators[id]); } catch(e){}
  delete decorators[id];
}

function removeTooltip(id){
  if(!lineTooltips[id]) return;
  try { if(map.hasLayer(lineTooltips[id])) map.removeLayer(lineTooltips[id]); } catch(e){}
  delete lineTooltips[id];
}

function showToast(message, duration=3000){
    let toast = document.createElement('div');
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.bottom = '20px';
    toast.style.right = '20px';
    toast.style.background = 'rgba(0,0,0,0.75)';
    toast.style.color = '#fff';
    toast.style.padding = '8px 12px';
    toast.style.borderRadius = '6px';
    toast.style.zIndex = 5000;
    toast.style.fontSize = '12px';
    toast.style.boxShadow = '0 2px 6px rgba(0,0,0,0.3)';
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s';
    document.body.appendChild(toast);
    setTimeout(()=>toast.style.opacity='1', 10);
    setTimeout(()=>{ toast.style.opacity='0'; setTimeout(()=>toast.remove(), 300); }, duration);
}

function addMarker(ap){
  const lat = parseFloat(ap.lat);
  const lng = parseFloat(ap.lng);
  
  if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
    console.warn('Invalid coordinates for AP:', ap.id, ap.name, lat, lng);
    return;
  }

  const status = getStatus(ap.ip);
  const iconType = (ap.type === 'odp') ? 'odp' : 'wifi';
  const icon = makeIconByType(status, iconType);
  const m = L.marker([lat, lng], {icon, draggable:true}).addTo(map);
  allLatLng.push([lat, lng]);

  const sinceText = getSince(ap.ip) || ap.lasttime || '';
  const popupHtml = `<div style="min-width:160px; font-size:12px">
    <b>${escapeHtml(ap.name)}</b><br>
    ${ap.type === 'odp' ? 'Type: ODP<br>' : 'IP: ' + escapeHtml(ap.ip) + '<br>'}
    Status: ${status.toUpperCase()}<br>
    Since: ${escapeHtml(sinceText)}<br>
    <div style="margin-top:6px; display:flex; gap:6px; flex-direction:column;">
      <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a class="btn" style="color:#fff;cursor:pointer;" onclick='openEditPopup(${JSON.stringify(ap)})'>Edit</a>
        <a class="btn" style="background:#9c27b0;color:#fff;cursor:pointer;" onclick="startEditCable('${ap.id}')">✏️ Kabel</a>
      </div>
    </div>
  </div>`;
  m.bindPopup(popupHtml);

  markers[ap.id]=m;
  if (ap.ip) markersByIp[ap.ip]=m;

  m.on('drag', e=>{
    if(ap.line && lines[ap.id]){
      const from = getMarkerForReference(ap.line);
      if(from) {
        const latlngs = buildBentLatLngs(from.getLatLng(), m.getLatLng(), ap.id, true);
        lines[ap.id].setLatLngs(latlngs);
      }
    }
    AP_LIST.forEach(child=>{
      if(child.line===ap.id && lines[child.id]) {
        const latlngs = buildBentLatLngs(m.getLatLng(), markers[child.id].getLatLng(), child.id, true);
        lines[child.id].setLatLngs(latlngs);
      }
    });
  });

  m.on('dragstart', e=>{
      m._originalLatLng = m.getLatLng();
  });

  m.on('dragend', async e=>{
      const pos = m.getLatLng();

      if(!confirm('Anda yakin ingin memindahkan AP "'+ap.name+'" ke koordinat baru?')){
          m.setLatLng(m._originalLatLng);
          if(ap.line && lines[ap.id]){
              const from = getMarkerForReference(ap.line);
              if(from) lines[ap.id].setLatLngs(buildBentLatLngs(from.getLatLng(), m._originalLatLng, ap.id, true));
          }
          AP_LIST.forEach(child=>{
              if(child.line === ap.id && lines[child.id]){
                  lines[child.id].setLatLngs(buildBentLatLngs(m._originalLatLng, markers[child.id].getLatLng(), child.id, true));
              }
          });
          showToast('Perubahan dibatalkan');
          return;
      }

      try{
          const resp = await fetch('update_coord.php',{
              method:'POST',
              headers:{'Content-Type':'application/json'},
              body: JSON.stringify({id:ap.id, lat:pos.lat, lng:pos.lng, router_ip:MT_IP})
          });
          if(!resp.ok) throw new Error('HTTP '+resp.status);
          const j = await resp.json();

          if(j.ok){
              showToast('Koordinat AP berhasil diperbarui!', 3000);
              setTimeout(()=>location.reload(), 800);
          } else {
              showToast('Gagal menyimpan koordinat', 3000);
              m.setLatLng(m._originalLatLng);
          }
      } catch(err){
          showToast('Gagal menyimpan koordinat: '+err.message, 4000);
          m.setLatLng(m._originalLatLng);
      }
  });
}

AP_LIST.forEach(a=>addMarker(a));

Object.values(LINES_JSON).forEach(ld => {
  const ap = AP_BY_ID[ld.toId];
  if(!ap) return;
  const fromMarker = getMarkerForReference(ld.fromRef);
  let fromLatLng = null;
  if (fromMarker) fromLatLng = fromMarker.getLatLng();
  else {
    const fromAp = AP_BY_ID[ld.fromRef];
    if(fromAp) fromLatLng = L.latLng(fromAp.lat, fromAp.lng);
    else fromLatLng = L.latLng(ld.latlngs[0][0], ld.latlngs[0][1]);
  }
  const toMarker = markers[ld.toId];
  if(!toMarker) return;
  
  const toLatLng = toMarker.getLatLng();
  const visible = isLineInView(fromLatLng, toLatLng);

  const targetStatus = getStatus(ap.ip);
  let colorWord;
  if (targetStatus === 'down') { colorWord = 'red'; }
  else if (targetStatus === 'up') { colorWord = normalizeColor(ld.color || ap.lineColor || 'lime'); }
  else { colorWord = normalizeColor(ld.color || ap.lineColor || 'gray'); }

  lineColors[ap.id] = colorWord;

  const poly = createPolylineFor(ap.id, fromLatLng, toLatLng, { color: colorWord, weight: 2, opacity: 1 }, true);
  poly.addTo(map);
  lines[ap.id] = poly;

  linesMeta[ap.id] = { fromRef: ld.fromRef, toId: ld.toId };
});

if(allLatLng.length>0) map.fitBounds(L.latLngBounds(allLatLng),{padding:[40,40]});

function openEditPopup(ap){
    const popupHtml = `<div style="font-size:12px">
        <h4>Edit: ${escapeHtml(ap.name)}</h4>
        <p><strong>Lat:</strong> ${ap.lat}</p>
        <p><strong>Lng:</strong> ${ap.lng}</p>
        <button onclick="alert('Edit lengkap di dashboard')">Buka di Dashboard</button>
    </div>`;
    const popup = L.popup().setLatLng([ap.lat, ap.lng]).setContent(popupHtml).openOn(map);
}
</script>
<script src="js/cable-editor.js"></script>
</body>
</html>
