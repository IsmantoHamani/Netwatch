<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['ip'], $_SESSION['user'], $_SESSION['pass'])){
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'not_authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if(!$data || empty($data['apId']) || !is_array($data['waypoints'])){
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'invalid_input']);
    exit;
}

$mt_ip = $_SESSION['ip'];
$safe_ip = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $mt_ip);
$dataFile = __DIR__ . "/ap_data_{$safe_ip}.json";

if(!file_exists($dataFile)){
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'data_file_not_found']);
    exit;
}

$apList = json_decode(file_get_contents($dataFile), true) ?: [];

// Cari AP berdasarkan ID
$apIndex = -1;
foreach($apList as $idx => $ap){
    if(isset($ap['id']) && $ap['id'] === $data['apId']){
        $apIndex = $idx;
        break;
    }
}

if($apIndex === -1){
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'ap_not_found']);
    exit;
}

// Simpan waypoints ke AP
$apList[$apIndex]['waypoints'] = $data['waypoints'];

// Simpan ke file
if(false === file_put_contents($dataFile, json_encode($apList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'save_failed']); 
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Waypoints kabel berhasil disimpan',
    'waypoints' => $data['waypoints']
]);
?>
