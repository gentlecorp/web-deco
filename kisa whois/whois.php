<?php
// 이 파일 없으면 작동 불가
// OPENAPI 코드는 data.go.kr 에서 발급 가능 (자세한건 whois.kr 참고)
// $DAILY_LIMIT 값 조절 시 일일 한도 조절 가능 (개발 계정일 경우 최대 1만, 이용 계정의 경우 10만 까지 가능.)
// 일일 한도를 API 계정의 허용 수를 넘어서면 접근거부됨 (반복시 차단)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$DAILY_LIMIT = 10000;
$LOG_FILE = __DIR__ . "/whois_count.txt";
$SERVICE_KEY = "OPENAPI 키 입력";

function fail($msg) {
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function getToday() {
    return date("Y-m-d");
}

function getTodayCount() {
    global $LOG_FILE;
    if (!file_exists($LOG_FILE)) return 0;
    
    $fp = fopen($LOG_FILE, "r");
    if (!$fp) return 0;
    
    if (flock($fp, LOCK_SH)) {
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } else {
        $raw = false;
    }
    fclose($fp);

    if ($raw === false) return 0;
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data["date"] ?? "") !== getToday()) return 0;
    
    return (int)($data["count"] ?? 0);
}

function increaseCount() {
    global $LOG_FILE;
    
    $fp = fopen($LOG_FILE, "c+");
    if (!$fp) return;

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $data = json_decode($raw, true);
        
        if (!is_array($data) || ($data["date"] ?? "") !== getToday()) {
            $count = 1;
        } else {
            $count = ((int)($data["count"] ?? 0)) + 1;
        }

        $newData = json_encode([
            "date" => getToday(),
            "count" => $count
        ]);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $newData);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$query = trim($_GET["q"] ?? "");
if ($query === "") {
    fail("검색어 없음");
}

if (getTodayCount() >= $DAILY_LIMIT) {
    fail("오늘 한도(1만회) 초과");
}

$isIP = preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $query) || strpos($query, ":") !== false;

if ($isIP) {
    $url = "https://apis.data.go.kr/B551505/whois/ip_address?serviceKey=" . $SERVICE_KEY . "&query=" . urlencode($query) . "&answer=json";
} else {
    $url = "https://apis.data.go.kr/B551505/whois/domain_name?serviceKey=" . $SERVICE_KEY . "&query=" . urlencode($query) . "&answer=json";
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_FOLLOWLOCATION => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    fail("API 호출 실패 (HTTP $httpCode)");
}

if (trim($response) === "") {
    fail("API가 빈 응답을 보냄");
}

increaseCount();
echo $response;
