<?php
/* =========================================================================
   Baraka Education — Meta Conversions API (CAPI) endpoint
   Fayl: /api/event.php   (cPanel / CloudLinux — PHP, sozlashsiz ishlaydi)

   Qo'llab-quvvatlanadigan hodisalar (req.body.eventType orqali):
     - PageView              (saytga kirganda)
     - Lead                  (BEPUL QATNASHISH formasi: ism + telefon)
     - CompleteRegistration  (Telegram kanalga obuna tugmasi)

   Texnik:
     - Telefon tozalash (faqat raqam -> 998901234567)
     - ph (telefon) va fn (ism) -> SHA-256 (trim + kichik harf) MAJBURIY
     - client_ip_address, client_user_agent, _fbp, _fbc ajratiladi
     - har so'rovga unikal event_id (browser Pixel bilan deduplikatsiya)
     - to'liq try-catch, UX hech qachon buzilmaydi
   ========================================================================= */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* --- Tezkor tekshiruv: brauzerda /api/event.php ni ochsangiz shu chiqadi --- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'msg' => 'CAPI endpoint ishlayapti. POST yuboring.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

/* =========================================================================
   1) KONFIGURATSIYA
   Avval .env o'qiladi. Agar zip qilishda .env yo'qolib qolsa (yashirin fayl),
   pastdagi FALLBACK qiymatlar ishlatiladi — shunda baribir ishlayveradi.
   ========================================================================= */
function bc_load_env($path) {
    if (!is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(trim(substr($line, $pos + 1)), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}
bc_load_env(__DIR__ . '/../.env');

$PIXEL_ID     = getenv('META_PIXEL_ID');
$ACCESS_TOKEN = getenv('META_ACCESS_TOKEN');

/* FALLBACK — .env topilmasa ishlatiladi (PHP fayli web orqali ochilmaydi). */
if (!$PIXEL_ID)     $PIXEL_ID     = '1590070006082105';
if (!$ACCESS_TOKEN) $ACCESS_TOKEN = 'EABAER69o7gUBRzyTjYupHF8V6z5YEGgRuSoF90mooukKzT0pMegZAZCNdTAzqVYhuZA3uIeZBK6wOZCdT0WeoNAA3e27tnZBXShJU4L133Kn3ditMQFAQeOn3QJUyDn2BiDvS6513NKfj1b1QAuUuSUvBPgyhfSIKb0muGmVuBdQDbbykZAlx2ZCXPZAGVnP3zfngdOOWB6ZAW6A757yC5ozeK38imT0u8E0MhQANZC';

$GRAPH_VERSION = 'v21.0';

if (!$PIXEL_ID || !$ACCESS_TOKEN) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server sozlanmagan (PIXEL_ID/TOKEN yo\'q)']);
    exit;
}

/* =========================================================================
   2) YORDAMCHI FUNKSIYALAR
   ========================================================================= */

/* Meta uchun maydon: trim + kichik harf + SHA-256 (hex) */
function bc_hash($value) {
    if ($value === null) return null;
    $value = trim(mb_strtolower($value, 'UTF-8'));
    if ($value === '') return null;
    return hash('sha256', $value);
}

/* Telefonni tozalash: "+998 90 000 00 00" -> "998901234567" */
function bc_clean_phone($raw) {
    if ($raw === null) return null;
    $digits = preg_replace('/\D+/', '', $raw);   // faqat raqamlar
    if ($digits === null || $digits === '') return null;
    if (strpos($digits, '998') !== 0) {          // 998 prefiksini ta'minlash
        $digits = '998' . ltrim($digits, '0');
    }
    return $digits;
}

/* Haqiqiy mijoz IP manzili (Vercel/cPanel/Cloudflare header'lari) */
function bc_client_ip() {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if ($ip !== '') return $ip;
        }
    }
    return '';
}

/* Unikal event_id (front-end yubormasa, shu yerda yaratiladi) */
function bc_gen_event_id() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return uniqid('evt_', true);
    }
}

/* =========================================================================
   3) SO'ROVNI O'QISH
   ========================================================================= */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$eventType = isset($body['eventType']) ? trim($body['eventType']) : '';
$allowed   = ['PageView', 'Lead', 'CompleteRegistration'];

if (!in_array($eventType, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Noto\'g\'ri eventType']);
    exit;
}

/* event_id — browser Pixel bilan bir xil bo'lishi uchun front-end'dan keladi */
$eventId = !empty($body['event_id'])
    ? preg_replace('/[^A-Za-z0-9_\-]/', '', $body['event_id'])
    : bc_gen_event_id();
if ($eventId === '') $eventId = bc_gen_event_id();

/* _fbp / _fbc — avval body'dan, bo'lmasa cookie'dan */
$fbp = !empty($body['fbp']) ? $body['fbp'] : (isset($_COOKIE['_fbp']) ? $_COOKIE['_fbp'] : null);
$fbc = !empty($body['fbc']) ? $body['fbc'] : (isset($_COOKIE['_fbc']) ? $_COOKIE['_fbc'] : null);

/* =========================================================================
   4) USER_DATA (Match Quality 90%+)
   ========================================================================= */
$userData = [
    'client_ip_address' => bc_client_ip(),
    'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
];
if (!empty($fbp)) $userData['fbp'] = $fbp;
if (!empty($fbc)) $userData['fbc'] = $fbc;

/* Faqat Lead'da ism va telefon bo'ladi -> MAJBURIY SHA-256 */
if ($eventType === 'Lead') {
    if (!empty($body['phone'])) {
        $phone = bc_clean_phone($body['phone']);     // 998901234567
        if ($phone) $userData['ph'] = hash('sha256', $phone);
    }
    if (!empty($body['name'])) {
        $fn = bc_hash($body['name']);                // ism (trim+lower+sha256)
        if ($fn) $userData['fn'] = $fn;
    }
}

/* bo'sh qiymatlarni olib tashlash */
$userData = array_filter($userData, function ($v) {
    return $v !== null && $v !== '';
});

/* =========================================================================
   5) EVENT OBYEKTI
   ========================================================================= */
$sourceUrl = !empty($body['event_source_url'])
    ? $body['event_source_url']
    : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');

$event = [
    'event_name'       => $eventType,
    'event_time'       => time(),
    'event_id'         => $eventId,
    'action_source'    => 'website',
    'event_source_url' => $sourceUrl,
    'user_data'        => $userData,
];

$payload = ['data' => [$event]];

/* Test rejimi: Events Manager > Test Events kodi bilan tekshirish uchun */
if (!empty($body['test_event_code'])) {
    $payload['test_event_code'] = $body['test_event_code'];
}

/* =========================================================================
   6) META'GA YUBORISH (cURL — PHP'dagi "axios") + TRY/CATCH
   ========================================================================= */
$url = "https://graph.facebook.com/{$GRAPH_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);

try {
    if (!function_exists('curl_init')) {
        throw new Exception('cURL kengaytmasi serverda yoqilmagan');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception('cURL xato: ' . $curlErr);
    }

    http_response_code(200);
    echo json_encode([
        'ok'          => ($httpCode >= 200 && $httpCode < 300),
        'event_id'    => $eventId,
        'fb_status'   => $httpCode,
        'fb_response' => json_decode($resp, true),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    /* Xatolik bo'lsa ham 200 qaytaramiz — sayt va forma ishlayveradi */
    http_response_code(200);
    echo json_encode([
        'ok'       => false,
        'event_id' => $eventId,
        'error'    => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
