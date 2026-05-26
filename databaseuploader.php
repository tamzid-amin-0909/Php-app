<?php
/**
 * databaseuploader.php
 * Place this at: /app/databaseuploader.php
 * database.json must be at: /app/database.json
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── CONFIG ──────────────────────────────────────────────────────────────────

define('DB_PATH', __DIR__ . '/database.json');

define('RM_AUTH', 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiI2ODg4YzMxYmY2NmZlZTk5YWRhYjJiZjkiLCJlbWFpbCI6InllYW1pbmFsdGF3c2lmQGdtYWlsLmNvbSIsIm5hbWUiOiJIQUNLRVIgMDA3Iiwicm9sZSI6InVzZXIiLCJkZXZpY2VJZCI6IjcyMzY2YTU1IiwiaWF0IjoxNzc3NzQzNDMwfQ.VGfdj5nbgn9bhghmjPVHzlfU7IP-CFK9l3PSSLyyZ2o');

define('BASE_API', 'https://api.redwansmethod.com');

$COURSES = [
    ['id' => '694ea8c903a0f734e8b68247', 'subject' => 'english'],
    ['id' => '694ea94dde6c9d2b770cecfa', 'subject' => 'ict'],
    ['id' => '6931e5ae10efdc5c7232a04f', 'subject' => 'physics'],
    ['id' => '694ea7e920e2df4356c9ff1e', 'subject' => 'bangla'],
    ['id' => '6931e4f59dd4de5bdb2d0f57', 'subject' => 'general_math'],
    ['id' => '6931e47941505d8b6ae2c8c1', 'subject' => 'higher_math'],
    ['id' => '6931e3e541505d8b6ae2c7f6', 'subject' => 'biology'],
    ['id' => '6931f9f641505d8b6ae2e2b4', 'subject' => 'bgs'],
    ['id' => '6931e56a41505d8b6ae2cbd0', 'subject' => 'chemistry'],
];

// ─── ROUTER ──────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'get_db':
        actionGetDb();
        break;

    case 'update_rm':
        actionUpdateRm($COURSES);
        break;

    case 'upload_merge':
        actionUploadMerge();
        break;

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────

/** Return current database.json content */
function actionGetDb() {
    if (!file_exists(DB_PATH)) {
        jsonResponse(['error' => 'database.json not found on server'], 404);
    }
    header('Content-Type: application/json');
    readfile(DB_PATH);
    exit;
}

/** Fetch all RM courses, merge into database.json, save, return zip */
function actionUpdateRm($courses) {

    set_time_limit(300); // 5 min for all API calls

    $log = [];

    // ── 1. Load current DB ──────────────────────────────────────────────────
    if (!file_exists(DB_PATH)) {
        jsonResponse(['error' => 'database.json not found. Upload it first.'], 404);
    }

    $oldDbRaw = file_get_contents(DB_PATH);
    $mainDatabase = json_decode($oldDbRaw, true);

    if (!$mainDatabase) {
        jsonResponse(['error' => 'database.json is invalid JSON'], 500);
    }

    $log[] = ['status' => 'ok', 'msg' => 'Loaded current database.json'];

    // ── 2. Fetch each course from RM API ────────────────────────────────────
    foreach ($courses as $course) {

        $courseId  = $course['id'];
        $subjectKey = $course['subject'];

        $log[] = ['status' => 'info', 'msg' => "Fetching course: $subjectKey ($courseId)"];

        try {
            $courseData = getFullCourseStructure($courseId);
        } catch (Exception $e) {
            $log[] = ['status' => 'error', 'msg' => "Failed to fetch $subjectKey: " . $e->getMessage()];
            continue;
        }

        // Format into nested tree
        $formatted = formatData($courseData);

        // Merge into mainDatabase
        if (!isset($mainDatabase['content'])) {
            $mainDatabase['content'] = [];
        }

        if (!isset($mainDatabase['content'][$subjectKey])) {
            $log[] = ['status' => 'warn', 'msg' => "Subject key '$subjectKey' not in content — creating it"];
            $mainDatabase['content'][$subjectKey] = [
                'title'    => ucfirst($subjectKey),
                'type'     => 'category',
                'children' => []
            ];
        }

        if (!isset($mainDatabase['content'][$subjectKey]['children'])) {
            $mainDatabase['content'][$subjectKey]['children'] = [];
        }

        foreach ($formatted as $k => $v) {
            $mainDatabase['content'][$subjectKey]['children'][$k] = $v;
        }

        $log[] = ['status' => 'ok', 'msg' => "Merged $subjectKey successfully"];
    }

    // ── 3. Save new database.json ────────────────────────────────────────────
    $newDbRaw = json_encode($mainDatabase);
    file_put_contents(DB_PATH, $newDbRaw);
    $log[] = ['status' => 'ok', 'msg' => 'Saved new database.json to server'];

    // ── 4. Build zip (old + new) and stream it ───────────────────────────────
    $zipPath = sys_get_temp_dir() . '/rm_update_' . time() . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        jsonResponse(['error' => 'Failed to create zip'], 500);
    }

    $zip->addFromString('database_OLD.json', $oldDbRaw);
    $zip->addFromString('database_NEW.json', $newDbRaw);
    $zip->addFromString('update_log.json', json_encode($log, JSON_PRETTY_PRINT));
    $zip->close();

    // Stream zip to browser
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="rm_database_update.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

/** Manual: accept POSTed JSON file, merge with DB, save, return zip */
function actionUploadMerge() {

    if (empty($_FILES['subject_file'])) {
        jsonResponse(['error' => 'No file uploaded'], 400);
    }

    $file = $_FILES['subject_file'];
    $subjectKey = strtolower(trim($_POST['subject_key'] ?? ''));

    if (!$subjectKey) {
        jsonResponse(['error' => 'subject_key is required'], 400);
    }

    $json = json_decode(file_get_contents($file['tmp_name']), true);
    if (!$json) {
        jsonResponse(['error' => 'Uploaded file is not valid JSON'], 400);
    }

    if (!file_exists(DB_PATH)) {
        jsonResponse(['error' => 'database.json not found on server'], 404);
    }

    $oldDbRaw = file_get_contents(DB_PATH);
    $mainDatabase = json_decode($oldDbRaw, true);

    $formatted = formatData($json);

    if (!isset($mainDatabase['content'][$subjectKey]['children'])) {
        $mainDatabase['content'][$subjectKey]['children'] = [];
    }

    foreach ($formatted as $k => $v) {
        $mainDatabase['content'][$subjectKey]['children'][$k] = $v;
    }

    $newDbRaw = json_encode($mainDatabase);
    file_put_contents(DB_PATH, $newDbRaw);

    jsonResponse(['success' => true, 'msg' => "Merged '$subjectKey' into database.json"]);
}

// ─── RM API HELPERS ───────────────────────────────────────────────────────────

function rmGet($endpoint) {
    $ch = curl_init(BASE_API . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json, text/plain, */*',
            'authorization: ' . RM_AUTH,
            'origin: https://redwansmethod.com',
            'referer: https://redwansmethod.com/',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) throw new Exception("cURL error: $err");

    $data = json_decode($body, true);
    if (!$data)  throw new Exception("Invalid JSON response from API");

    return $data;
}

function getFullCourseStructure($courseId) {

    $subjectRes = rmGet("/subjects/fetchAllSubjects/$courseId");
    $subjects   = $subjectRes['subjects'] ?? [];
    $structure  = [];

    foreach ($subjects as $sub) {

        $chapterRes = rmGet("/chapters/fetchAllChapters/{$sub['_id']}");
        $chapters   = $chapterRes['chapters'] ?? [];
        $subObj     = ['subject' => $sub['subjectName'], 'chapters' => []];

        foreach ($chapters as $chap) {
            $videoRes = rmGet("/videos/fetchAllVideos/{$chap['_id']}");
            $subObj['chapters'][] = [
                'chapterName' => $chap['chapterName'],
                'lectures'    => $videoRes['videos'] ?? [],
            ];
        }

        $structure[] = $subObj;
    }

    return $structure;
}

// ─── FORMAT HELPER (mirrors the JS formatData in HTML) ───────────────────────

function formatData($data) {

    $root = [];

    foreach ($data as $item) {

        $subKey = strtolower($item['subject'] ?? '');
        $subKey = preg_replace('/\s+/', '_', $subKey);
        $subKey = preg_replace('/[()]/', '', $subKey);

        $root[$subKey] = [
            'title'       => $item['subject'] ?? '',
            'thumbnail'   => $item['subjectThumbnail'] ?? $item['thumbnail'] ?? '',
            'type'        => 'category',
            'description' => $item['subject'] ?? '',
            'children'    => [],
        ];

        foreach (($item['chapters'] ?? []) as $cIdx => $chap) {

            $chapKey = 'id_' . ($cIdx + 1);

            $root[$subKey]['children'][$chapKey] = [
                'title'     => $chap['chapterName'] ?? '',
                'thumbnail' => $chap['chapterThumbnail'] ?? $chap['thumbnail'] ?? '',
                'type'      => 'category',
                'children'  => [],
            ];

            foreach (($chap['lectures'] ?? []) as $lec) {

                $lecNum = $lec['videoNumber'] ?? '0';
                $lecKey = 'lec_' . $lecNum;

                $descArr = [];
                if (!empty($lec['videoLectureSheetURL']))
                    $descArr[] = 'Lecture Sheet--> ' . $lec['videoLectureSheetURL'];
                if (!empty($lec['videoLectureNoteURL']) || !empty($lec['videoNoteURL']))
                    $descArr[] = 'Note--> ' . ($lec['videoLectureNoteURL'] ?? $lec['videoNoteURL']);
                if (!empty($lec['videoPracticeSheetURL']))
                    $descArr[] = 'Practice Sheet--> ' . $lec['videoPracticeSheetURL'];
                if (!empty($lec['videoSolveSheetURL']))
                    $descArr[] = 'Solve Sheet--> ' . $lec['videoSolveSheetURL'];

                $root[$subKey]['children'][$chapKey]['children'][$lecKey] = [
                    'title'       => $lec['videoTitle'] ?? '',
                    'type'        => 'video',
                    'description' => implode("\n", $descArr),
                    'url'         => $lec['videoYoutubeURL'] ?? $lec['videoURL'] ?? '',
                ];
            }
        }
    }

    return $root;
}

// ─── UTIL ─────────────────────────────────────────────────────────────────────

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
