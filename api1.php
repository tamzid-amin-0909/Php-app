<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

session_start();
$file = 'database.json';

if (!file_exists($file)) {
    file_put_contents($file, json_encode([]));
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================

function loadDatabase() {
    global $file;
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function saveDatabase($data) {
    global $file;
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) return false;
    return file_put_contents($file, $encoded) !== false;
}

function getAuthToken() {
    // Check if the helper function exists, if not, manually grab from $_SERVER
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
    }

    if (isset($headers['Authorization'])) {
        return str_replace('Bearer ', '', $headers['Authorization']);
    }
    
    // Check $_SERVER directly for common Authorization headers if previous methods failed
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    }

    return $_GET['token'] ?? '';
}


function validateSession($token = null) {
    if (!$token) $token = getAuthToken();
    if (!$token) return null;

    if (isset($_SESSION['user_sessions'][$token])) {
        $session = $_SESSION['user_sessions'][$token];
        if (time() - $session['created_at'] > 86400) {
            unset($_SESSION['user_sessions'][$token]);
            return null;
        }
        $_SESSION['user_sessions'][$token]['last_active'] = time();
        return $session['user_id'];
    }
    return null;
}

function getContentWithoutChildren($item) {
    $result = [];
    foreach ($item as $key => $value) {
        if ($key !== 'children') {
            $result[$key] = $value;
        }
    }
    return $result;
}

/**
 * Traverse unlimited nesting via path like "subject/chapter/topic/subtopic"
 * Each path segment steps into the node, then into ->children for the next segment.
 */
function getContentPath($data, $path) {
    $keys = explode('/', trim($path, '/'));
    $current = $data;

    foreach ($keys as $i => $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return null;
        }
        $current = $current[$key];

        // Step into children for next segment (not on last key)
        if ($i < count($keys) - 1) {
            if (!isset($current['children']) || !is_array($current['children'])) {
                return null;
            }
            $current = $current['children'];
        }
    }
    return $current;
}

/**
 * Recursively walk $stored content tree and graft any children branches
 * that are missing in $incoming (because they were never lazy-loaded by the browser).
 */
function mergeMissingChildren(&$incoming, $stored) {
    foreach ($stored as $slug => $storedItem) {
        if (!isset($incoming[$slug])) continue; // item deleted — skip

        if (isset($storedItem['children']) && is_array($storedItem['children']) && count($storedItem['children']) > 0) {
            if (!isset($incoming[$slug]['children']) || !is_array($incoming[$slug]['children']) || count($incoming[$slug]['children']) === 0) {
                // incoming has no children for this node — restore from stored
                $incoming[$slug]['children'] = $storedItem['children'];
            } else {
                // Both have children — recurse deeper
                mergeMissingChildren($incoming[$slug]['children'], $storedItem['children']);
            }
        }
    }
}

// Read request body ONCE (php://input can only be read once)
$rawInput = file_get_contents('php://input');

// ==========================================
// ROUTE HANDLING
// ==========================================

$action = $_GET['action'] ?? '';
$type   = $_GET['type']   ?? '';

// ==========================================
// AUTH ENDPOINTS
// ==========================================

if ($action === 'auth' && $type === 'login') {
    $data     = json_decode($rawInput, true) ?? [];
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(["error" => "Username and password required"]);
        exit();
    }

    $db   = loadDatabase();
    $user = $db['users'][$username] ?? null;

    if (!$user || $user['password'] !== $password) {
        http_response_code(401);
        echo json_encode(["error" => "Invalid credentials"]);
        exit();
    }

    if (!isset($_SESSION['user_sessions'])) {
        $_SESSION['user_sessions'] = [];
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['user_sessions'][$token] = [
        'user_id'     => $username,
        'role'        => $user['role'],
        'created_at'  => time(),
        'last_active' => time()
    ];

    echo json_encode([
        "token" => $token,
        "user"  => [
            "id"     => $username,
            "role"   => $user['role'],
            "access" => $user['access'] ?? []
        ]
    ]);
    exit();
}

if ($action === 'auth' && $type === 'logout') {
    $token = getAuthToken();
    if ($token && isset($_SESSION['user_sessions'][$token])) {
        unset($_SESSION['user_sessions'][$token]);
    }
    echo json_encode(["status" => "success"]);
    exit();
}

// Validate a remember-me cookie token against the database
// POST body: { userId, rememberToken }
if ($action === 'auth' && $type === 'cookie') {
    $data        = json_decode($rawInput, true) ?? [];
    $userId      = $data['userId']      ?? '';
    $cookieToken = $data['rememberToken'] ?? '';

    if (!$userId || !$cookieToken) {
        http_response_code(400);
        echo json_encode(["error" => "userId and rememberToken required"]);
        exit();
    }

    $db   = loadDatabase();
    $user = $db['users'][$userId] ?? null;

    if (!$user || ($user['rememberToken'] ?? '') !== $cookieToken) {
        // Token mismatch or user gone — cookie is invalid
        http_response_code(401);
        echo json_encode(["error" => "Invalid or expired remember token"]);
        exit();
    }

    // Token valid — create a fresh session and return it
    if (!isset($_SESSION['user_sessions'])) {
        $_SESSION['user_sessions'] = [];
    }
    $sessionToken = bin2hex(random_bytes(32));
    $_SESSION['user_sessions'][$sessionToken] = [
        'user_id'     => $userId,
        'role'        => $user['role'],
        'created_at'  => time(),
        'last_active' => time()
    ];

    echo json_encode([
        "token" => $sessionToken,
        "user"  => [
            "id"     => $userId,
            "role"   => $user['role'],
            "access" => $user['access'] ?? []
        ]
    ]);
    exit();
}

if ($action === 'auth' && $type === 'validate') {
    $token  = getAuthToken();
    $userId = validateSession($token);

    if (!$userId) {
        http_response_code(401);
        echo json_encode(["error" => "Invalid or expired session"]);
        exit();
    }

    $db   = loadDatabase();
    $user = $db['users'][$userId] ?? null;

    if (!$user) {
        http_response_code(401);
        echo json_encode(["error" => "User not found"]);
        exit();
    }

    echo json_encode([
        "user" => [
            "id"     => $userId,
            "role"   => $user['role'],
            "access" => $user['access'] ?? []
        ]
    ]);
    exit();
}

// ==========================================
// DATA ENDPOINTS
// ==========================================

if ($action === 'data' && $type === 'home') {
    $db     = loadDatabase();
    $token  = getAuthToken();
    $userId = validateSession($token);

    $response = [
        "settings" => $db['settings'] ?? [],
        "notices"  => $db['notices']  ?? [],
        "content"  => []
    ];

    if (isset($db['content'])) {
        foreach ($db['content'] as $slug => $item) {
            $response['content'][$slug] = getContentWithoutChildren($item);
        }
    }

    if ($userId) {
        $user = $db['users'][$userId] ?? null;
        if ($user) {
            // Use "currentUser" key — avoids collision with the "users" DB key
            // so if the client ever saves db back, it won't inject a stray "user" field
            $response['currentUser'] = [
                "id"     => $userId,
                "role"   => $user['role'],
                "access" => $user['access'] ?? []
            ];
        }
    }

    echo json_encode($response);
    exit();
}

if ($action === 'data' && $type === 'children') {
    $path = $_GET['path'] ?? '';
    if (!$path) {
        http_response_code(400);
        echo json_encode(["error" => "Path required"]);
        exit();
    }

    $db   = loadDatabase();
    $item = getContentPath($db['content'] ?? [], $path);

    if ($item === null) {
        http_response_code(404);
        echo json_encode(["error" => "Content not found at path: " . $path]);
        exit();
    }

    if (!isset($item['children']) || !is_array($item['children'])) {
        echo json_encode(["children" => []]);
        exit();
    }

    // Return immediate children WITHOUT their own children (lazy load boundary)
    $children = [];
    foreach ($item['children'] as $slug => $child) {
        $children[$slug] = getContentWithoutChildren($child);
    }

    echo json_encode(["children" => $children]);
    exit();
}

if ($action === 'data' && $type === 'full') {
    $token  = getAuthToken();
    $userId = validateSession($token);

    if (!$userId) {
        http_response_code(401);
        echo json_encode(["error" => "Authentication required"]);
        exit();
    }

    $db   = loadDatabase();
    $user = $db['users'][$userId] ?? null;

    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Admin access required"]);
        exit();
    }

    $path = $_GET['path'] ?? '';
    if (!$path) {
        echo json_encode(["content" => $db['content'] ?? []]);
    } else {
        $item = getContentPath($db['content'] ?? [], $path);
        if ($item === null) {
            http_response_code(404);
            echo json_encode(["error" => "Path not found"]);
        } else {
            echo json_encode(["content" => $item]);
        }
    }
    exit();
}

// ==========================================
// WRITE ENDPOINT (admin only)
// ==========================================

if ($action === 'write') {
    $token  = getAuthToken();
    $userId = validateSession($token);

    if (!$userId) {
        http_response_code(401);
        echo json_encode(["error" => "Authentication required"]);
        exit();
    }

    $db   = loadDatabase();
    $user = $db['users'][$userId] ?? null;

    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["error" => "Admin access required"]);
        exit();
    }

    if (empty($rawInput)) {
        http_response_code(400);
        echo json_encode(["error" => "Empty request body"]);
        exit();
    }

    $incoming = json_decode($rawInput, true);
    if ($incoming === null) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON: " . json_last_error_msg()]);
        exit();
    }

    // ── PATCH MODE ──────────────────────────────────────────────────────────
    // Payload: { path: "subject/chapter", data: { ...node fields... } }
    // Only updates the specific node; all other branches are untouched.
    if (isset($incoming['__patch']) && $incoming['__patch'] === true) {
        $patchPath = trim($incoming['path'] ?? '', '/');
        $patchData = $incoming['data'] ?? [];

        if ($patchPath === '') {
            // Root-level fields (settings, notices, users) — never touch content children
            foreach ($patchData as $k => $v) {
                if ($k !== 'content') {
                    $db[$k] = $v;
                }
            }
        } else {
            $keys    = explode('/', $patchPath);
            $current = &$db['content'];

            foreach ($keys as $i => $key) {
                if (!isset($current[$key])) {
                    http_response_code(404);
                    echo json_encode(["error" => "Path segment not found: $key"]);
                    exit();
                }
                if ($i === count($keys) - 1) {
                    // Preserve stored children if patch omits them
                    if (isset($current[$key]['children']) && !isset($patchData['children'])) {
                        $patchData['children'] = $current[$key]['children'];
                    }
                    $current[$key] = $patchData;
                } else {
                    if (!isset($current[$key]['children'])) {
                        http_response_code(404);
                        echo json_encode(["error" => "No children at: $key"]);
                        exit();
                    }
                    $current = &$current[$key]['children'];
                }
            }
        }

        if (saveDatabase($db)) {
            echo json_encode(["status" => "success"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to save database"]);
        }
        exit();
    }

    // ── FULL DB WRITE MODE ───────────────────────────────────────────────────
    // Browser sends its in-memory `db` object. Strip ephemeral client-only keys
    // that must never be persisted to the database file.
    unset($incoming['user']);         // old key name — strip if present
    unset($incoming['currentUser']);  // new key name — strip if present

    if (isset($incoming['content'])) {
        mergeMissingChildren($incoming['content'], $db['content'] ?? []);
    }

    if (saveDatabase($incoming)) {
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to save database"]);
    }
    exit();
}

// ==========================================
// LEGACY SUPPORT (backward compatibility)
// ==========================================

if ($action === 'read') {
    $token  = getAuthToken();
    $userId = validateSession($token);

    if ($userId) {
        $db   = loadDatabase();
        $user = $db['users'][$userId] ?? null;

        if ($user && $user['role'] === 'admin') {
            echo json_encode($db);
        } else {
            http_response_code(403);
            echo json_encode(["error" => "Admin access required"]);
        }
    } else {
        $db       = loadDatabase();
        $response = [
            "settings" => $db['settings'] ?? [],
            "notices"  => $db['notices']  ?? [],
            "content"  => []
        ];
        if (isset($db['content'])) {
            foreach ($db['content'] as $slug => $item) {
                $response['content'][$slug] = getContentWithoutChildren($item);
            }
        }
        echo json_encode($response);
    }
    exit();
}

http_response_code(400);
echo json_encode(["error" => "Invalid request"]);
?>
