<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit();

function getEncKey() {
    $token = getToken();
    if (!$token) return null;
    return hash('sha256', $token . '-enc-v1', true);
}

function sendJSON($data, $httpCode = 200) {
    if ($httpCode !== 200) http_response_code($httpCode);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $encKey = getEncKey();
    if ($encKey) {
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($json, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
        echo json_encode(['e' => base64_encode($enc), 'i' => base64_encode($iv)]);
    } else {
        echo $json;
    }
    exit();
}

$file = 'database.json';
if (!file_exists($file)) file_put_contents($file, json_encode(['users'=>[],'settings'=>[],'notices'=>[],'content'=>[],'sessions'=>[]]));

function loadDB() {
    global $file;
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveDB($data) {
    global $file;
    unset($data['user'], $data['currentUser']);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) return false;
    return rename($tmp, $file);
}

function getToken() {
    if (!empty($_GET['token'])) return trim($_GET['token']);
    if (function_exists('getallheaders')) {
        $h = getallheaders();
    } else {
        $h = [];
        foreach ($_SERVER as $k => $v)
            if (str_starts_with($k, 'HTTP_'))
                $h[str_replace(' ','-',ucwords(strtolower(str_replace('_',' ',substr($k,5)))))] = $v;
    }
    if (isset($h['Authorization'])) return str_replace('Bearer ','',trim($h['Authorization']));
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) return str_replace('Bearer ','',trim($_SERVER['HTTP_AUTHORIZATION']));
    return '';
}

function validateSession($token = null) {
    if (!$token) $token = getToken();
    if (!$token) return null;
    $db = loadDB();
    $s  = $db['sessions'][$token] ?? null;
    if (!$s) return null;
    if (time() - $s['lastActive'] > 86400) {
        unset($db['sessions'][$token]);
        saveDB($db);
        return null;
    }
    if (time() - $s['lastActive'] > 60) {
        $db['sessions'][$token]['lastActive'] = time();
        saveDB($db);
    }
    return $s['userId'];
}

function createSession($userId) {
    $db = loadDB();
    if (!isset($db['sessions'])) $db['sessions'] = [];
    foreach ($db['sessions'] as $tok => $s)
        if (time() - $s['lastActive'] > 86400) unset($db['sessions'][$tok]);
    $token = bin2hex(random_bytes(32));
    $db['sessions'][$token] = ['userId'=>$userId,'createdAt'=>time(),'lastActive'=>time()];
    saveDB($db);
    return $token;
}

function requireAdmin() {
    $uid = validateSession();
    if (!$uid) sendJSON(['error'=>'Authentication required'], 401);
    $db = loadDB();
    $role = $db['users'][$uid]['role'] ?? '';
    if ($role !== 'admin') sendJSON(['error'=>'Admin required'], 403);
    return $uid;
}

// Returns ['uid'=>..., 'role'=>..., 'user'=>...] for admin or subadmin
function requireAdminOrSubAdmin() {
    $uid = validateSession();
    if (!$uid) sendJSON(['error'=>'Authentication required'], 401);
    $db   = loadDB();
    $user = $db['users'][$uid] ?? null;
    if (!$user) sendJSON(['error'=>'User not found'], 401);
    $role = $user['role'] ?? '';
    if ($role !== 'admin' && $role !== 'subadmin') sendJSON(['error'=>'Access denied'], 403);
    return ['uid'=>$uid, 'role'=>$role, 'user'=>$user];
}

// Check subadmin has a specific permission key
function subAdminCan($perm) {
    $auth = requireAdminOrSubAdmin();
    if ($auth['role'] === 'admin') return $auth;
    $perms = $auth['user']['subAdminPerms'] ?? [];
    // Check exact key or wildcard
    if (!in_array($perm, $perms) && !in_array('all', $perms)) {
        sendJSON(['error'=>"Permission denied: $perm required"], 403);
    }
    return $auth;
}

// Check if subadmin can edit a specific content path
function subAdminCanEditContent($contentPath) {
    $auth = requireAdminOrSubAdmin();
    if ($auth['role'] === 'admin') return $auth;
    $perms = $auth['user']['subAdminPerms'] ?? [];
    if (in_array('content:all', $perms) || in_array('all', $perms)) return $auth;
    // Check if any content:slug matches the start of contentPath
    foreach ($perms as $p) {
        if (str_starts_with($p, 'content:')) {
            $slug = substr($p, 8); // after "content:"
            if ($contentPath === $slug || str_starts_with($contentPath, $slug . '/')) return $auth;
        }
    }
    sendJSON(['error'=>'No permission to edit this content'], 403);
}

function noChildren($item) {
    return array_filter($item, fn($k) => $k !== 'children', ARRAY_FILTER_USE_KEY);
}

function getNode($data, $path) {
    $parts = explode('/', trim($path, '/'));
    $cur = $data;
    foreach ($parts as $i => $k) {
        if (!isset($cur[$k])) return null;
        $cur = $cur[$k];
        if ($i < count($parts)-1) { if (!isset($cur['children'])) return null; $cur = $cur['children']; }
    }
    return $cur;
}

function &parentRef(&$data, $path) {
    $null = null;
    $parts = explode('/', trim($path, '/'));
    $cur = &$data;
    for ($i = 0; $i < count($parts)-1; $i++) {
        $k = $parts[$i];
        if (!isset($cur[$k])) return $null;
        $cur = &$cur[$k];
        if (!isset($cur['children'])) $cur['children'] = [];
        $cur = &$cur['children'];
    }
    return $cur;
}

function graftChildren(&$inc, $stored) {
    foreach ($stored as $slug => $s) {
        if (!isset($inc[$slug])) continue;
        if (!empty($s['children'])) {
            if (empty($inc[$slug]['children'])) $inc[$slug]['children'] = $s['children'];
            else graftChildren($inc[$slug]['children'], $s['children']);
        }
    }
}

function preserveChildren(&$data, $slug, &$node) {
    $stored = $data[$slug]['children'] ?? null;
    if ($stored !== null && count($stored) > 0) $node['children'] = $stored;
    elseif (!isset($node['children'])) $node['children'] = [];
}

// Build user response object
function userResponse($uid, $user) {
    return [
        'id'             => $uid,
        'role'           => $user['role'],
        'access'         => $user['access'] ?? [],
        'subAdminPerms'  => $user['subAdminPerms'] ?? null,
        'isTelegramUser' => $user['isTelegramUser'] ?? false,
        'telegramName'   => $user['telegramName'] ?? null,
        'trialEnd'       => $user['trialEnd'] ?? null,
    ];
}

$raw    = file_get_contents('php://input');
$action = $_GET['action'] ?? '';
$type   = $_GET['type']   ?? '';

// ── TELEGRAM LOGIN ──────────────────────────────────────────────────────────
if ($action === 'auth' && $type === 'telegram') {
    $d      = json_decode($raw, true) ?? [];
    $tgData = $d['tgData'] ?? null;
    if (!$tgData || !is_array($tgData)) sendJSON(['error'=>'Missing Telegram data'], 400);

    // Load bot token from DB settings (primary) or fall back to hardcoded
    $db0      = loadDB();
    $botToken = trim($db0['settings']['telegramBotToken'] ?? '8714027089:AAHDntPcUe_qwDmhnJCTrm9FDuQqoX_8X_U');
    if (!$botToken) sendJSON(['error'=>'Bot token not configured in Settings'], 400);

    // Verify Telegram hash — must NOT include hash field in check string
    $checkHash = $tgData['hash'] ?? '';
    if (!$checkHash) sendJSON(['error'=>'Missing hash from Telegram data'], 400);

    $dataArr = [];
    foreach ($tgData as $k => $v) {
        if ($k !== 'hash') $dataArr[] = "$k=$v";
    }
    sort($dataArr);
    $dataCheckStr = implode("\n", $dataArr);
    $secretKey    = hash('sha256', $botToken, true); // raw bytes
    $computed     = hash_hmac('sha256', $dataCheckStr, $secretKey);

    if (!hash_equals($computed, $checkHash)) {
        sendJSON(['error'=>'Telegram auth verification failed. Check bot token in Settings.'], 401);
    }
    if ((time() - intval($tgData['auth_date'] ?? 0)) > 86400) {
        sendJSON(['error'=>'Telegram auth data expired. Please try again.'], 401);
    }

    $tgId   = (string)($tgData['id'] ?? '');
    $tgName = trim(($tgData['first_name'] ?? '') . ' ' . ($tgData['last_name'] ?? ''));
    $tgUser = $tgData['username'] ?? $tgId;
    $deviceId = trim($d['deviceId'] ?? '');

    $db = loadDB();

    // Find existing user by telegramId
    $existingUid = null;
    foreach ($db['users'] as $uid => $u) {
        if (($u['telegramId'] ?? '') === $tgId) { $existingUid = $uid; break; }
    }

    $trialMinutes = intval($db['settings']['trialMinutes'] ?? 8);
    $trialMs = $trialMinutes * 60 * 1000;
    $trialAccess = $db['settings']['trialAccess'] ?? ['none'];

    if (!$existingUid) {
        $newUid = 'tg_' . $tgId;
        // Make sure uid is unique
        if (isset($db['users'][$newUid])) $newUid = 'tg_' . $tgId . '_' . substr(bin2hex(random_bytes(3)),0,4);
        $nowMs = intval(microtime(true) * 1000);
        $db['users'][$newUid] = [
            'password'       => '',
            'role'           => 'student',
            'access'         => $trialAccess,
            'telegramId'     => $tgId,
            'telegramName'   => $tgName,
            'telegramUser'   => $tgUser,
            'isTelegramUser' => true,
            'trialStart'     => $nowMs,
            'trialEnd'       => $nowMs + $trialMs,
        ];
        $existingUid = $newUid;
        saveDB($db);
        $db = loadDB();
    } else {
        $db['users'][$existingUid]['telegramName'] = $tgName;
        $db['users'][$existingUid]['telegramUser'] = $tgUser;
        // Update trial access in case settings changed (only for trial users who haven't expired)
        $nowMs = intval(microtime(true) * 1000);
        if (isset($db['users'][$existingUid]['trialEnd']) && $db['users'][$existingUid]['trialEnd'] > $nowMs) {
            $db['users'][$existingUid]['access'] = $trialAccess;
        }
        saveDB($db);
        $db = loadDB();
    }

    $user = $db['users'][$existingUid];

    // Block login if trial expired (and user has no purchased access)
    $nowMsCheck = intval(microtime(true) * 1000);
    $trialEndMs = $user['trialEnd'] ?? null;
    $hasRealAccess = !empty($user['access']) && $user['access'] !== ['none'] && !in_array('none', $user['access'] ?? []);
    $trialResetBy  = $user['trialResetBy'] ?? null; // set when admin resets trial
    if ($trialEndMs && $trialEndMs < $nowMsCheck && !$hasRealAccess) {
        sendJSON(['error'=>'trial_expired', 'message'=>'Your free trial has expired. Please contact the admin to purchase access.'], 403);
    }

    // Device lock check
    if ($user['role'] !== 'admin' && $deviceId) {
        $locked   = $user['lockedDevice'] ?? null;
        $lockTime = $user['deviceLockTime'] ?? 0;
        $expired  = (intval(microtime(true)*1000) - $lockTime) >= 3*24*60*60*1000;
        if ($locked && $locked !== $deviceId && !$expired) {
            $daysLeft = ceil((3*24*60*60*1000 - (intval(microtime(true)*1000) - $lockTime)) / 86400000);
            sendJSON(['error'=>'Device locked','daysLeft'=>$daysLeft], 403);
        }
    }

    $token = createSession($existingUid);
    $db = loadDB();
    $db['users'][$existingUid]['currentSession'] = $token;
    if ($user['role'] !== 'admin' && $deviceId) {
        $db['users'][$existingUid]['lockedDevice']   = $deviceId;
        $db['users'][$existingUid]['deviceLockTime'] = intval(microtime(true)*1000);
    }
    saveDB($db);

    $u = loadDB()['users'][$existingUid];
    sendJSON(['token'=>$token, 'user'=>userResponse($existingUid, $u)]);
}

// ── STANDARD LOGIN ──────────────────────────────────────────────────────────
if ($action === 'auth' && $type === 'login') {
    $d        = json_decode($raw, true) ?? [];
    $username = trim($d['username'] ?? '');
    $password = $d['password'] ?? '';
    $deviceId = trim($d['deviceId'] ?? '');
    if (!$username || !$password) sendJSON(['error'=>'Fields required'], 400);

    $db   = loadDB();
    $user = $db['users'][$username] ?? null;
    if (!$user || $user['password'] !== $password) sendJSON(['error'=>'Invalid credentials'], 401);

    if ($user['role'] !== 'admin' && $deviceId) {
        $locked   = $user['lockedDevice'] ?? null;
        $lockTime = $user['deviceLockTime'] ?? 0;
        $nowMs    = intval(microtime(true)*1000);
        $expired  = ($nowMs - $lockTime) >= 3*24*60*60*1000;
        if ($locked && $locked !== $deviceId && !$expired) {
            $daysLeft = ceil((3*24*60*60*1000 - ($nowMs - $lockTime)) / 86400000);
            sendJSON(['error'=>'Device locked','daysLeft'=>$daysLeft], 403);
        }
    }

    $token   = createSession($username);
    $rmToken = bin2hex(random_bytes(24));
    $db = loadDB();
    $db['users'][$username]['rememberToken']  = $rmToken;
    $db['users'][$username]['currentSession'] = $token;
    if ($user['role'] !== 'admin' && $deviceId) {
        $db['users'][$username]['lockedDevice']   = $deviceId;
        $db['users'][$username]['deviceLockTime'] = intval(microtime(true)*1000);
    }
    saveDB($db);

    sendJSON(['token'=>$token,'rememberToken'=>$rmToken, 'user'=>userResponse($username, $db['users'][$username])]);
}

// ── LOGOUT ──────────────────────────────────────────────────────────────────
if ($action === 'auth' && $type === 'logout') {
    $t = getToken();
    if ($t) { $db = loadDB(); unset($db['sessions'][$t]); saveDB($db); }
    sendJSON(['status'=>'success']);
}

// ── VALIDATE ────────────────────────────────────────────────────────────────
if ($action === 'auth' && $type === 'validate') {
    $token = getToken();
    $uid   = validateSession($token);
    if (!$uid) sendJSON(['error'=>'Session expired'], 401);

    $db   = loadDB();
    $user = $db['users'][$uid] ?? null;
    if (!$user) sendJSON(['error'=>'User gone'], 401);

    if (str_starts_with($user['currentSession'] ?? '', 'INVALIDATED-')) {
        unset($db['sessions'][$token]);
        saveDB($db);
        sendJSON(['error'=>'session_invalidated','message'=>'Session ended by administrator.'], 401);
    }

    sendJSON(['user'=>userResponse($uid, $user) + ['lockedDevice'=>$user['lockedDevice']??null]]);
}

// ── COOKIE ──────────────────────────────────────────────────────────────────
if ($action === 'auth' && $type === 'cookie') {
    $d     = json_decode($raw, true) ?? [];
    $uid   = $d['userId'] ?? '';
    $ctok  = $d['rememberToken'] ?? '';
    $devId = $d['deviceId'] ?? '';
    if (!$uid || !$ctok) sendJSON(['error'=>'Missing fields'], 400);

    $db   = loadDB();
    $user = $db['users'][$uid] ?? null;
    if (!$user || ($user['rememberToken']??'') !== $ctok) sendJSON(['error'=>'Invalid cookie'], 401);

    if ($user['role'] !== 'admin' && $devId) {
        $locked  = $user['lockedDevice'] ?? null;
        $nowMs   = intval(microtime(true)*1000);
        $expired = ($nowMs - ($user['deviceLockTime']??0)) >= 3*24*60*60*1000;
        if ($locked && $locked !== $devId && !$expired) sendJSON(['error'=>'device_locked'], 403);
        $db['users'][$uid]['lockedDevice']   = $devId;
        $db['users'][$uid]['deviceLockTime'] = $nowMs;
        saveDB($db);
    }

    $token = createSession($uid);
    sendJSON(['token'=>$token, 'user'=>userResponse($uid, $db['users'][$uid])]);
}

// ── HOME DATA ────────────────────────────────────────────────────────────────
if ($action === 'data' && $type === 'home') {
    $db  = loadDB();
    $uid = validateSession();
    $out = ['settings'=>$db['settings']??[],'notices'=>$db['notices']??[],'content'=>[]];
    foreach ($db['content']??[] as $slug => $item) $out['content'][$slug] = noChildren($item);
    if ($uid && isset($db['users'][$uid]))
        $out['currentUser'] = userResponse($uid, $db['users'][$uid]);
    sendJSON($out);
}

// ── CHILDREN ────────────────────────────────────────────────────────────────
if ($action === 'data' && $type === 'children') {
    $path = $_GET['path'] ?? '';
    if (!$path) sendJSON(['error'=>'path required'], 400);
    $db   = loadDB();
    $node = getNode($db['content']??[], $path);
    if ($node === null) sendJSON(['error'=>"Not found: $path"], 404);
    $kids = [];
    foreach ($node['children']??[] as $slug => $child) $kids[$slug] = noChildren($child);
    sendJSON(['children'=>$kids]);
}

// ── FULL READ (admin + subadmin) ─────────────────────────────────────────────
if ($action === 'read') {
    $auth  = requireAdminOrSubAdmin();
    $db    = loadDB();
    $slim  = ($_GET['slim'] ?? '') === '1'; // slim=1 skips content — for admin panel user/settings tabs
    unset($db['sessions']);

    if ($auth['role'] === 'subadmin') {
        $perms = $auth['user']['subAdminPerms'] ?? [];
        $hasUserPerm    = in_array('users',   $perms) || in_array('all', $perms);
        $hasContentPerm = !empty(array_filter($perms, fn($p) => str_starts_with($p, 'content:'))) || in_array('all', $perms);
        $out = ['settings' => $db['settings'] ?? [], 'notices' => $db['notices'] ?? []];
        if ($hasUserPerm) {
            $out['users'] = array_filter($db['users'] ?? [], function($u) use ($auth) {
                return isset($u['createdBy']) && $u['createdBy'] === $auth['uid'];
            });
        }
        if ($hasContentPerm && !$slim) $out['content'] = $db['content'] ?? [];
        sendJSON($out);
    }

    // Owner: slim omits content (fast load for users/settings tabs)
    if ($slim) {
        sendJSON(['users'=>$db['users']??[],'settings'=>$db['settings']??[],'notices'=>$db['notices']??[]]);
    }
    sendJSON($db);
}

// ── PATCH CONTENT ────────────────────────────────────────────────────────────
if ($action === 'patch' && $type === 'content') {
    $req = json_decode($raw, true);
    if (!$req) sendJSON(['error'=>'Bad JSON'], 400);
    $op   = $req['op']   ?? '';
    $path = trim($req['path']??$req['from']??'', '/');

    // Permission check: admin always passes; subadmin checked per-content-path
    $auth = requireAdminOrSubAdmin();
    if ($auth['role'] === 'subadmin') {
        $perms = $auth['user']['subAdminPerms'] ?? [];
        $hasAll = in_array('content:all', $perms) || in_array('all', $perms);
        if (!$hasAll) {
            // Check if path starts with an allowed slug
            $rootSlug = explode('/', $path)[0];
            $allowed = false;
            foreach ($perms as $p) {
                if ($p === "content:$rootSlug" || str_starts_with($p, "content:$rootSlug")) {
                    $allowed = true; break;
                }
            }
            if (!$allowed) sendJSON(['error'=>'No permission to edit this content section'], 403);
        }
    }

    $db = loadDB();

    if ($op === 'set') {
        $node = $req['data'] ?? null;
        if (!$path || $node===null) sendJSON(['error'=>'path+data required'], 400);
        $parts = explode('/', $path); $slug = array_pop($parts); $pp = implode('/', $parts);
        if ($pp === '') { preserveChildren($db['content'], $slug, $node); $db['content'][$slug] = $node; }
        else { $par = &parentRef($db['content'], $path); if ($par===null) sendJSON(['error'=>'Parent not found'], 404); preserveChildren($par, $slug, $node); $par[$slug]=$node; }
        saveDB($db); sendJSON(['status'=>'success']);
    }

    if ($op === 'delete') {
        $parts = explode('/', $path); $slug = array_pop($parts); $pp = implode('/', $parts);
        if ($pp==='') unset($db['content'][$slug]);
        else { $par = &parentRef($db['content'], $path); if ($par!==null) unset($par[$slug]); }
        saveDB($db); sendJSON(['status'=>'success']);
    }

    if ($op === 'move' || $op === 'copy') {
        $from = trim($req['from']??'', '/'); $to = trim($req['to']??'', '/');
        if (!$from||!$to) sendJSON(['error'=>'from+to required'], 400);
        $fparts=explode('/',$from); $fslug=array_pop($fparts); $fpp=implode('/',$fparts);
        $tparts=explode('/',$to);   $tslug=array_pop($tparts); $tpp=implode('/',$tparts);
        $fpar = ($fpp==='') ? $db['content'] : (($r=&parentRef($db['content'],$from))!==null?$r:[]);
        if (!isset($fpar[$fslug])) sendJSON(['error'=>'Source not found'], 404);
        $nodeCopy = json_decode(json_encode($fpar[$fslug]),true);
        if ($tpp==='') $db['content'][$tslug]=$nodeCopy;
        else { $tpar=&parentRef($db['content'],$to); if($tpar===null) sendJSON(['error'=>'Dest not found'], 404); $tpar[$tslug]=$nodeCopy; }
        if ($op==='move') {
            if ($fpp==='') unset($db['content'][$fslug]);
            else { $fp=&parentRef($db['content'],$from); unset($fp[$fslug]); }
        }
        saveDB($db); sendJSON(['status'=>'success']);
    }
    sendJSON(['error'=>"Unknown op: $op"], 400);
}

// ── PATCH SETTINGS (owner only) ──────────────────────────────────────────────
if ($action === 'patch' && $type === 'settings') {
    requireAdmin();
    $d = json_decode($raw, true);
    if ($d===null) sendJSON(['error'=>'Bad JSON'], 400);
    $db = loadDB(); $db['settings'] = $d; saveDB($db);
    sendJSON(['status'=>'success']);
}

// ── PATCH NOTICES ────────────────────────────────────────────────────────────
if ($action === 'patch' && $type === 'notices') {
    $auth = requireAdminOrSubAdmin();
    $perms = $auth['user']['subAdminPerms'] ?? [];
    if ($auth['role'] === 'subadmin' && !in_array('notices', $perms) && !in_array('all', $perms))
        sendJSON(['error'=>'No permission: notices'], 403);
    $d = json_decode($raw, true);
    if ($d===null) sendJSON(['error'=>'Bad JSON'], 400);
    $db = loadDB(); $db['notices'] = $d; saveDB($db);
    sendJSON(['status'=>'success']);
}

// ── PATCH USER ───────────────────────────────────────────────────────────────
if ($action === 'patch' && $type === 'user') {
    $auth = requireAdminOrSubAdmin();
    $perms = $auth['user']['subAdminPerms'] ?? [];
    if ($auth['role'] === 'subadmin' && !in_array('users', $perms) && !in_array('all', $perms))
        sendJSON(['error'=>'No permission: users'], 403);

    $req = json_decode($raw, true) ?? [];
    $uid = $req['userId'] ?? '';
    $d   = $req['data']   ?? null;
    if (!$uid || $d===null) sendJSON(['error'=>'userId+data required'], 400);

    $db = loadDB();
    $ex = $db['users'][$uid] ?? null;

    // Subadmin restrictions
    if ($auth['role'] === 'subadmin') {
        if ($ex && ($ex['createdBy'] ?? '') !== $auth['uid']) {
            sendJSON(['error'=>'Cannot edit this user'], 403);
        }
        if (in_array($d['role']??'', ['admin','subadmin'])) sendJSON(['error'=>'Cannot assign elevated role'], 403);
        if (in_array($ex['role']??'', ['admin','subadmin'])) sendJSON(['error'=>'Cannot edit admin/subadmin'], 403);
        // Track who created/edited
        if (!$ex && !isset($d['createdBy'])) $d['createdBy'] = $auth['uid'];
        $d['lastEditedBy'] = $auth['uid'];
    }

    // Preserve sensitive fields if not explicitly set
    foreach(['currentSession','rememberToken','lockedDevice','deviceLockTime','telegramId','telegramName','isTelegramUser','trialStart','trialEnd','createdBy'] as $f)
        if (!array_key_exists($f,$d) && isset($ex[$f])) $d[$f] = $ex[$f];

    $db['users'][$uid] = $d;
    saveDB($db);
    sendJSON(['status'=>'success']);
}

// ── DELETE USER ──────────────────────────────────────────────────────────────
if ($action === 'patch' && $type === 'deleteuser') {
    $auth = requireAdminOrSubAdmin();
    $perms = $auth['user']['subAdminPerms'] ?? [];
    if ($auth['role'] === 'subadmin' && !in_array('users', $perms) && !in_array('all', $perms))
        sendJSON(['error'=>'No permission: users'], 403);

    $req = json_decode($raw, true) ?? [];
    $uid = $req['userId'] ?? '';
    if (!$uid) sendJSON(['error'=>'userId required'], 400);
    $db  = loadDB();
    $target = $db['users'][$uid] ?? null;
    if ($auth['role'] === 'subadmin' && in_array($target['role']??'', ['admin','subadmin']))
        sendJSON(['error'=>'Cannot delete admin/subadmin'], 403);
    unset($db['users'][$uid]);
    foreach($db['sessions']??[] as $tok=>$s) if($s['userId']===$uid) unset($db['sessions'][$tok]);
    saveDB($db);
    sendJSON(['status'=>'success']);
}

// ── PROMOTE/UPDATE SUBADMIN (owner only) ─────────────────────────────────────
if ($action === 'patch' && $type === 'subadmin') {
    requireAdmin();
    $req  = json_decode($raw, true) ?? [];
    $uid  = $req['userId'] ?? '';
    $perms = $req['permissions'] ?? [];
    if (!$uid) sendJSON(['error'=>'userId required'], 400);
    $db = loadDB();
    if (!isset($db['users'][$uid])) sendJSON(['error'=>'User not found'], 404);
    $db['users'][$uid]['role']           = 'subadmin';
    $db['users'][$uid]['subAdminPerms']  = $perms;
    $db['users'][$uid]['currentSession'] = 'INVALIDATED-'.time().'-'.bin2hex(random_bytes(4));
    saveDB($db);
    sendJSON(['status'=>'success']);
}

// ── REVOKE SUBADMIN (owner only) ─────────────────────────────────────────────
if ($action === 'patch' && $type === 'revoke-subadmin') {
    requireAdmin();
    $req = json_decode($raw, true) ?? [];
    $uid = $req['userId'] ?? '';
    if (!$uid) sendJSON(['error'=>'userId required'], 400);
    $db = loadDB();
    if (!isset($db['users'][$uid])) sendJSON(['error'=>'User not found'], 404);
    $db['users'][$uid]['role'] = 'student';
    unset($db['users'][$uid]['subAdminPerms']);
    $db['users'][$uid]['currentSession'] = 'INVALIDATED-'.time().'-'.bin2hex(random_bytes(4));
    saveDB($db);
    sendJSON(['status'=>'success']);
}

// ── RESET TRIAL (owner only) ─────────────────────────────────────────────────
if ($action === 'patch' && $type === 'reset-trial') {
    requireAdmin();
    $req = json_decode($raw, true) ?? [];
    $uid = $req['userId'] ?? '';
    if (!$uid) sendJSON(['error'=>'userId required'], 400);
    $db = loadDB();
    if (!isset($db['users'][$uid])) sendJSON(['error'=>'User not found'], 404);
    if (!($db['users'][$uid]['isTelegramUser'] ?? false)) sendJSON(['error'=>'Not a Telegram user'], 400);

    $trialMinutes = intval($db['settings']['trialMinutes'] ?? 8);
    $trialMs  = $trialMinutes * 60 * 1000;
    $trialAccess = $db['settings']['trialAccess'] ?? ['none'];
    $nowMs    = intval(microtime(true) * 1000);

    $db['users'][$uid]['trialStart']  = $nowMs;
    $db['users'][$uid]['trialEnd']    = $nowMs + $trialMs;
    $db['users'][$uid]['trialResetBy'] = 'admin';
    $db['users'][$uid]['access']      = $trialAccess;
    saveDB($db);
    sendJSON(['status'=>'success', 'trialEnd'=>$nowMs + $trialMs]);
}

// ── CHECK TG CHANNEL MEMBERSHIP ──────────────────────────────────────────────
if ($action === 'check_tg_channels') {
    $db       = loadDB();
    $channels = $db['settings']['requiredTgChannels'] ?? [];
    $botToken = $db['settings']['tgBotToken'] ?? '';

    if (empty($channels) || empty($botToken)) {
        sendJSON(['notJoined' => []]);
    }

    // Get tgUserId from the authenticated user
    $token = getToken();
    if (!$token) sendJSON(['notJoined' => []]);
    $uid = null;
    foreach ($db['sessions'] as $sess) {
        if (($sess['token'] ?? '') === $token) { $uid = $sess['userId'] ?? null; break; }
    }
    if (!$uid || empty($db['users'][$uid]['telegramId'])) sendJSON(['notJoined' => []]);

    $tgUserId  = $db['users'][$uid]['telegramId'];
    $notJoined = [];

    foreach ($channels as $ch) {
        $chatId = $ch['username'];
        // Normalize: strip https://t.me/ prefix to get @username
        if (strpos($chatId, 't.me/') !== false) {
            $chatId = '@' . preg_replace('#.*t\.me/#', '', $chatId);
        }
        if ($chatId[0] !== '@' && !is_numeric($chatId)) {
            $chatId = '@' . $chatId;
        }
        $url  = "https://api.telegram.org/bot{$botToken}/getChatMember?chat_id=" . urlencode($chatId) . "&user_id=" . urlencode($tgUserId);
        $resp = @file_get_contents($url);
        if ($resp === false) continue; // skip on network error
        $data   = json_decode($resp, true);
        $status = $data['result']['status'] ?? 'left';
        if (!in_array($status, ['member', 'administrator', 'creator'])) {
            $notJoined[] = $ch;
        }
    }

    sendJSON(['notJoined' => $notJoined]);
}

// ── FULL WRITE ───────────────────────────────────────────────────────────────
if ($action === 'write') {
    requireAdmin();
    if (empty($raw)) sendJSON(['error'=>'Empty body'], 400);
    $inc = json_decode($raw, true);
    if ($inc===null) sendJSON(['error'=>'Bad JSON: '.json_last_error_msg()], 400);
    $db = loadDB();
    if (isset($inc['content'])) graftChildren($inc['content'], $db['content']??[]);
    foreach(['users','settings','notices'] as $k) if(!isset($inc[$k])) $inc[$k]=$db[$k]??[];
    $inc['sessions'] = $db['sessions']??[];
    saveDB($inc);
    sendJSON(['status'=>'success']);
}

sendJSON(['error'=>"Bad request: action=$action type=$type"], 400);
?>
