<?php
/**
 * PHP File Explorer
 * Features: Login System, Recursive Search, 50GB Storage Monitor, Progress bar with Speed indicator, 
 * Cancel Upload, Sequential Upload (Unlimited files), Selection Counter, Media Player Modal, 
 * Custom UI Dialogs, Vanilla JS AJAX (No reloads), Folder Upload & Drag-and-Drop Support.
 */

// Start output buffering to prevent "headers already sent" issues during redirection
ob_start();
session_start();

// ================= SESSION & AUTH =================

// Hardcoded credentials
$auth_user = "admin";
$auth_pass = "manuelsintos21";

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle Login
$login_error = "";
if (isset($_POST['login_user'], $_POST['login_pass'])) {
    if ($_POST['login_user'] === $auth_user && $_POST['login_pass'] === $auth_pass) {
        $_SESSION['logged_in'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = "Invalid username or password.";
    }
}

// Protection: If not logged in, show login form
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - File Explorer</title>
    <style>
        :root { --primary: #2563eb; --bg: #f8fafc; --text: #1e293b; --danger: #ef4444; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); width: 100%; max-width: 350px; }
        h2 { margin-top: 0; text-align: center; color: var(--primary); }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; margin-top: 10px; }
        .error { color: var(--danger); font-size: 0.85rem; text-align: center; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>📂 Explorer Login</h2>
        <?php if ($login_error): ?>
            <div class="error"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="text" name="login_user" placeholder="Username" required autofocus autocomplete="username">
            <input type="password" name="login_pass" placeholder="Password" required autocomplete="current-password">
            <button type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>
<?php exit; endif; ?>

<?php
// ================= CONFIG & SECURITY =================
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Increase limits for large file handling
@set_time_limit(0); 
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '0');
@ini_set('max_input_time', '0');

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'files';
if (!file_exists($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$realBase = realpath($baseDir);

// 50GB Storage Limit in Bytes
$storageLimit = 50 * 1024 * 1024 * 1024;

/**
 * Validates path safety to prevent traversal attacks.
 */
function safePath($path, $realBase) {
    $realPath = realpath($path);
    if ($realPath === false) {
        $parent = realpath(dirname($path));
        return ($parent && strpos($parent, $realBase) === 0) ? $path : false;
    }
    return (strpos($realPath, $realBase) === 0) ? $realPath : false;
}

/**
 * Calculates total size of a directory recursively
 */
function getDirectorySize($path) {
    $size = 0;
    if (!file_exists($path)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * Calculates total count of files recursively
 */
function getTotalFileCount($path) {
    $count = 0;
    if (!file_exists($path)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) $count++;
    }
    return $count;
}

/**
 * Recursively deletes a file or directory.
 */
function recursiveDelete($path) {
    if (is_dir($path)) {
        $files = array_diff(scandir($path), array('.', '..'));
        foreach ($files as $file) {
            recursiveDelete($path . DIRECTORY_SEPARATOR . $file);
        }
        return @rmdir($path);
    }
    return @unlink($path);
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// Request Parameters
$reqDir = $_GET['dir'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_GET['ajax']);

$currentDir = safePath($realBase . DIRECTORY_SEPARATOR . $reqDir, $realBase) ?: $realBase;
$relativeDir = ltrim(str_replace($realBase, '', $currentDir), DIRECTORY_SEPARATOR);

// ================= ACTION HANDLERS =================

// Helper to return JSON and exit
function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// 1. UPLOAD HANDLER (Supports individual files and folders)
if (isset($_GET['action']) && $_GET['action'] === 'upload' && !empty($_FILES['upload'])) {
    $totalUsed = getDirectorySize($realBase);
    $newFilesSize = array_sum($_FILES['upload']['size']);
    if (($totalUsed + $newFilesSize) > $storageLimit) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'error' => 'Storage limit exceeded.']);
    }

    $files = $_FILES['upload'];
    $successCount = 0;
    
    // Check if we have a relative path for folder structure
    $relativePath = $_POST['relativePath'] ?? '';

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $name = basename($files['name'][$i]);
            
            // If relativePath is provided, we need to ensure the directory exists
            if (!empty($relativePath)) {
                $targetFile = $currentDir . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
                $targetDir = dirname($targetFile);
                if (!file_exists($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
            } else {
                $targetFile = $currentDir . DIRECTORY_SEPARATOR . $name;
            }

            if (safePath($targetFile, $realBase)) {
                if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                    $successCount++;
                }
            }
        }
    }
    sendJsonResponse(['success' => true, 'count' => $successCount]);
}

// 2. BULK DELETE
if (isset($_POST['bulk_delete']) && !empty($_POST['selected_items'])) {
    foreach ($_POST['selected_items'] as $itemPath) {
        $file = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
        if ($file && $file !== $realBase) {
            recursiveDelete($file);
        }
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
    exit;
}

// 3. NEW FOLDER
if (!empty($_POST['newfolder'])) {
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['newfolder']);
    if ($name) {
        $newFolderPath = $currentDir . DIRECTORY_SEPARATOR . $name;
        if (safePath($newFolderPath, $realBase) && !file_exists($newFolderPath)) {
            mkdir($newFolderPath, 0777);
        }
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
    exit;
}

// 4. INDIVIDUAL DELETE
if (isset($_GET['delete'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_GET['delete'], $realBase);
    if ($file && $file !== $realBase) {
        recursiveDelete($file);
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
    exit;
}

// 5. RENAME & MOVE
if (isset($_POST['rename_old'], $_POST['rename_new'])) {
    $old = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['rename_old'], $realBase);
    $newName = basename($_POST['rename_new']);
    if ($old && $old !== $realBase && !empty($newName)) {
        rename($old, dirname($old) . DIRECTORY_SEPARATOR . $newName);
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
    exit;
}
if (isset($_POST['move_file'], $_POST['move_target'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_file'], $realBase);
    $targetDir = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_target'], $realBase);
    if ($file && $targetDir && is_dir($targetDir)) {
        rename($file, $targetDir . DIRECTORY_SEPARATOR . basename($file));
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
    exit;
}

// 6. DOWNLOAD / PREVIEW HANDLER
if (isset($_GET['download'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_GET['download'], $realBase);
    if ($file && is_file($file)) {
        while (ob_get_level()) ob_end_clean();
        $size = filesize($file);
        $length = $size;
        $start = 0;
        $end = $size - 1;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
            'gif'  => 'image/gif',  'webp' => 'image/webp', 'svg'  => 'image/svg+xml',
            'mp4'  => 'video/mp4',  'webm' => 'video/webm', 'ogg'  => 'video/ogg',
            'mp3'  => 'audio/mpeg', 'wav'  => 'audio/wav'
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        if (isset($_SERVER['HTTP_RANGE'])) {
            list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            $range = explode('-', $range);
            $start = $range[0];
            $end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$size");
            $length = $end - $start + 1;
        }
        header("Content-Length: " . $length);
        $fp = fopen($file, 'rb');
        fseek($fp, $start);
        $buffer = 8192;
        while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
            if ($pos + $buffer > $end) $buffer = $end - $pos + 1;
            echo fread($fp, $buffer);
            flush();
        }
        fclose($fp);
        exit;
    }
}

// ================= VIEW LOGIC =================
$items = [];
$isSearch = !empty($searchQuery);

if ($isSearch) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realBase, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        $fName = $fileInfo->getFilename();
        if (stripos($fName, $searchQuery) !== false) {
            $fPath = $fileInfo->getPathname();
            $items[] = [
                'name' => $fName,
                'path' => ltrim(str_replace($realBase, '', $fPath), DIRECTORY_SEPARATOR),
                'isDir' => $fileInfo->isDir(),
                'mtime' => date("Y-m-d H:i", $fileInfo->getMTime()),
                'size' => $fileInfo->isDir() ? '--' : formatSize($fileInfo->getSize()),
                'type' => $fileInfo->isDir() ? 'Folder' : strtoupper(pathinfo($fName, PATHINFO_EXTENSION))
            ];
        }
    }
} else {
    $scanned = @scandir($currentDir);
    if ($scanned) {
        foreach ($scanned as $f) {
            if ($f === '.' || $f === '..') continue;
            $fPath = $currentDir . DIRECTORY_SEPARATOR . $f;
            $items[] = [
                'name' => $f,
                'path' => ltrim($relativeDir . '/' . $f, '/'),
                'isDir' => is_dir($fPath),
                'mtime' => date("Y-m-d H:i", filemtime($fPath)),
                'size' => is_dir($fPath) ? '--' : formatSize(filesize($fPath)),
                'type' => is_dir($fPath) ? 'Folder' : strtoupper(pathinfo($f, PATHINFO_EXTENSION))
            ];
        }
    }
}

usort($items, function($a, $b) {
    if ($a['isDir'] !== $b['isDir']) return $b['isDir'] - $a['isDir'];
    return strcasecmp($a['name'], $b['name']);
});

// Final Storage Calculation
$totalUsed = getDirectorySize($realBase);
$totalFilesStorage = getTotalFileCount($realBase);
$usedPercent = ($totalUsed / $storageLimit) * 100;

// Handle AJAX Fetching for Nav/Search
if ($isAjax) {
    sendJsonResponse([
        'dir' => $relativeDir,
        'search' => $searchQuery,
        'items' => $items,
        'stats' => [
            'used' => formatSize($totalUsed),
            'usedPercent' => number_format($usedPercent, 2),
            'totalFiles' => $totalFilesStorage,
            'isFull' => $usedPercent >= 100
        ]
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP File Explorer</title>
    <style>
        :root { --primary: #2563eb; --bg: #f8fafc; --text: #1e293b; --success: #22c55e; --danger: #ef4444; --border: #e2e8f0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 10px; }
        
        .storage-monitor { position: fixed; top: 10px; left: 10px; z-index: 1000; background: white; padding: 10px 14px; border-radius: 10px; width: 150px; pointer-events: none; border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .storage-monitor-header { font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 6px; display: flex; justify-content: space-between; }
        .storage-bar-bg { background: #f1f5f9; height: 8px; border-radius: 4px; overflow: hidden; }
        .storage-bar-fill { height: 100%; background: var(--primary); transition: width 0.5s ease; }
        .storage-bar-fill.warning { background: #f59e0b; }
        .storage-bar-fill.critical { background: var(--danger); }
        .storage-usage-text { font-size: 0.7rem; color: var(--text); margin-top: 5px; text-align: right; font-family: monospace; }
        .storage-count-text { font-size: 0.65rem; color: #64748b; margin-top: 2px; text-align: left; }

        .container { max-width: 1100px; margin: 60px auto 20px; background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .toolbar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; background: #f1f5f9; padding: 15px; border-radius: 8px; align-items: center; }
        
        .search-container { flex-grow: 1; display: flex; gap: 5px; position: relative; }
        .search-container input { width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; }
        .search-container input:focus { border-color: var(--primary); }

        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-size: 0.9rem; white-space: nowrap; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-outline { background: white; border: 1px solid #cbd5e1; color: var(--text); }
        .btn:hover { filter: brightness(1.1); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Unified Upload Button Styles */
        .upload-wrapper { position: relative; display: inline-block; }
        .dropdown-menu { position: absolute; top: 100%; left: 0; background: white; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 1500; display: none; min-width: 160px; margin-top: 4px; padding: 4px 0; }
        .dropdown-menu.active { display: block; }
        .dropdown-item { padding: 10px 16px; cursor: pointer; font-size: 0.9rem; transition: background 0.2s; display: flex; align-items: center; gap: 8px; color: var(--text); }
        .dropdown-item:hover { background: #f1f5f9; color: var(--primary); }

        .upload-controls { display: flex; align-items: flex-end; gap: 10px; width: 100%; margin-top: 15px; }
        .upload-status-group { display: none; flex-grow: 1; flex-direction: column; gap: 4px; }
        #uploadCountBadge { font-size: 0.8rem; font-weight: 700; color: var(--primary); }
        #progressContainer { background: #e2e8f0; border-radius: 10px; height: 24px; overflow: hidden; position: relative; width: 100%; }
        #progressBar { height: 100%; width: 0%; background: var(--primary); transition: width 0.1s linear; }
        #progressInfo { position: absolute; width: 100%; text-align: center; top: 0; line-height: 24px; font-size: 0.75rem; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.5); font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0 10px; }
        #uploadSizeBadge { font-size: 0.75rem; font-weight: 600; color: #64748b; margin-top: 2px; }
        #cancelUploadBtn { display: none; font-size: 0.75rem; padding: 4px 10px; height: 24px; }
        #notification { display: none; margin-bottom: 15px; padding: 12px; background: var(--success); color: white; border-radius: 8px; text-align: center; }

        .breadcrumb-container { margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .breadcrumb { font-size: 0.95rem; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
        .breadcrumb a { color: var(--primary); text-decoration: none; cursor: pointer; }
        .breadcrumb a:hover { text-decoration: underline; }
        .item-counter { font-size: 0.85rem; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; font-weight: 600; }

        .file-grid { display: grid; grid-template-columns: 40px 1fr 150px 100px 100px 120px; align-items: center; border-bottom: 1px solid var(--border); }
        .file-grid-header { font-weight: 600; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px 8px 0 0; padding: 10px 0; font-size: 0.85rem; text-transform: uppercase; color: #64748b; }
        
        .file-list { border: 1px solid var(--border); border-top: none; border-radius: 0 0 8px 8px; overflow: hidden; min-height: 100px; position: relative; }
        .file-list.drag-over { background: #eff6ff; border: 2px dashed var(--primary); }
        .drag-overlay-hint { display: none; position: absolute; top:0; left:0; width:100%; height:100%; pointer-events: none; align-items: center; justify-content: center; background: rgba(37, 99, 235, 0.05); z-index: 10; font-weight: bold; color: var(--primary); }
        .file-list.drag-over .drag-overlay-hint { display: flex; }

        .loading-overlay { display: none; position: absolute; top:0; left:0; width:100%; height:100%; background: rgba(255,255,255,0.7); z-index: 5; align-items: center; justify-content: center; font-weight: bold; color: var(--primary); }

        .file-item { padding: 12px 0; transition: background 0.1s; font-size: 0.9rem; user-select: none; cursor: default; }
        .file-item:hover { background: #f1f5f9; }
        .col { padding: 0 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .col-info { display: flex; align-items: center; gap: 8px; text-decoration: none; color: inherit; font-weight: 500; width: 100%; cursor: pointer; }
        .col-actions { display: flex; justify-content: flex-end; gap: 5px; }
        .icon-btn { padding: 6px; border-radius: 4px; border: none; background: transparent; cursor: pointer; color: #64748b; font-size: 1rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover { background: #e2e8f0; color: var(--primary); }

        .path-label { font-size: 0.75rem; color: #94a3b8; display: block; margin-top: 2px; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s ease; }
        .modal.active { display: flex; opacity: 1; }
        .modal-content { background-color: #fff; padding: 24px; border-radius: 12px; width: 90%; max-width: 850px; max-height: 90vh; overflow: auto; position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transform: scale(0.95); transition: transform 0.2s ease; }
        .modal.active .modal-content { transform: scale(1); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 12px; }
        .modal-title { font-weight: bold; font-size: 1.15rem; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .modal-close { font-size: 28px; font-weight: bold; cursor: pointer; color: #aaa; transition: color 0.2s; padding: 0 10px; line-height: 1; }
        .modal-close:hover { color: var(--danger); }
        .media-container { display: flex; justify-content: center; align-items: center; background: #111; border-radius: 8px; overflow: hidden; min-height: 300px; }
        .media-container img, .media-container video { max-width: 100%; max-height: 75vh; height: auto; display: block; }
        .media-container audio { width: 90%; padding: 40px 0; }
        .modal-body { margin-bottom: 24px; font-size: 0.95rem; line-height: 1.5; color: #475569; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-input { width: 93%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; outline: none; transition: border-color 0.2s; }
        .modal-input:focus { border-color: var(--primary); }

        .context-menu { position: absolute; display: none; background: #fff; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); z-index: 100; min-width: 150px; padding: 4px 0; }
        .context-menu div { padding: 10px 16px; cursor: pointer; font-size: 0.9rem; }
        .context-menu div:hover { background: #f1f5f9; color: var(--primary); }

        @media (max-width: 850px) { .file-grid { grid-template-columns: 40px 1fr 100px 100px; } .col-type, .col-date { display: none; } }
        @media (max-width: 600px) { .file-grid { grid-template-columns: 40px 1fr 80px; } .col-size { display: none; } .toolbar { flex-direction: column; align-items: stretch; } .storage-monitor { position: static; width: auto; margin-bottom: 10px; } .container { margin-top: 20px; } }
    </style>
</head>
<body>

<div class="storage-monitor">
    <div class="storage-monitor-header">
        <span>STORAGE USED</span>
        <span id="statPercent"><?= number_format($usedPercent, 2) ?>%</span>
    </div>
    <div class="storage-bar-bg">
        <div id="statBar" class="storage-bar-fill <?= $usedPercent > 90 ? 'critical' : ($usedPercent > 75 ? 'warning' : '') ?>" style="width: <?= min(100, $usedPercent) ?>%"></div>
    </div>
    <div id="statUsedText" class="storage-usage-text"><?= formatSize($totalUsed) ?> / 50 GB</div>
    <div id="statTotalFiles" class="storage-count-text" style="display:block">Total Files: <?= $totalFilesStorage ?></div>
</div>

<div class="container">
    <div id="notification"></div>
    <header>
        <h2 style="margin:0">📂 File Explorer</h2>
        <a href="?logout=1" class="btn btn-outline" style="font-size: 0.8rem;">🚪 Logout</a>
    </header>

    <div class="toolbar">
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <!-- Single Upload Dropdown -->
            <div class="upload-wrapper">
                <button id="uploadDropdownBtn" class="btn btn-primary" onclick="toggleUploadDropdown()" <?= $usedPercent >= 100 ? 'disabled' : '' ?>>📤 Upload ▼</button>
                <div id="uploadDropdownMenu" class="dropdown-menu">
                    <div class="dropdown-item" onclick="document.getElementById('uploadInput').click()">📄 Upload Files</div>
                    <div class="dropdown-item" onclick="document.getElementById('folderUploadInput').click()">📁 Upload Folder</div>
                </div>
            </div>

            <!-- Hidden Inputs -->
            <input type="file" id="uploadInput" multiple style="display: none" onchange="uploadItems('file')">
            <input type="file" id="folderUploadInput" webkitdirectory mozdirectory style="display: none" onchange="uploadItems('folder')">
            
            <button class="btn btn-outline" onclick="openFolderModal()">🆕 New Folder</button>
            <button class="btn btn-danger" id="bulkDeleteBtn" disabled onclick="submitBulkDelete()">🗑️ Delete</button>
        </div>

        <div class="search-container">
            <input type="text" id="searchInput" placeholder="Search files in all folders..." value="<?= htmlspecialchars($searchQuery) ?>" autocomplete="off" onkeyup="handleSearchKeyUp(event)">
            <button type="button" class="btn btn-primary" onclick="triggerSearch()">🔍</button>
            <button type="button" id="clearSearchBtn" class="btn btn-outline" style="<?= $isSearch ? '' : 'display:none' ?>" onclick="clearSearch()">✖</button>
        </div>

        <div class="upload-controls">
            <div id="uploadStatusGroup" class="upload-status-group">
                <div id="uploadCountBadge">Preparing upload...</div>
                <div id="progressContainer">
                    <div id="progressBar"></div>
                    <div id="progressInfo">Waiting to start...</div>
                </div>
                <div id="uploadSizeBadge"></div>
            </div>
            <button id="cancelUploadBtn" class="btn btn-danger" onclick="abortUpload()">✖ Cancel</button>
        </div>
    </div>

    <div class="breadcrumb-container">
        <div class="breadcrumb" id="breadcrumbTrail">
            <!-- Rendered by JS -->
        </div>
        <div class="item-counter" id="itemCounter">
            Showing <?= $itemCountVisible ?> item(s)
        </div>
    </div>

    <form id="fileForm" onsubmit="return false">
        <div class="file-grid file-grid-header">
            <div class="col" style="text-align:center"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></div>
            <div class="col">Name</div>
            <div class="col col-date">Modified</div>
            <div class="col col-type">Type</div>
            <div class="col col-size">Size</div>
            <div class="col" style="text-align:right">Actions</div>
        </div>
        
        <!-- File list acts as Drop Zone -->
        <div class="file-list" id="explorerBody" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
            <div class="loading-overlay" id="loadingOverlay">🔄 Loading...</div>
            <div class="drag-overlay-hint">🚀 Drop files or folders here to upload</div>
            <div id="fileListContent">
                <!-- Rendered by JS -->
            </div>
        </div>
    </form>
</div>

<!-- Modals & Context Menus -->
<div id="folderModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header"><span class="modal-title">Create New Folder</span><span class="modal-close" onclick="closeModal('folderModal')">&times;</span></div>
        <div class="modal-body"><input type="text" id="newFolderName" placeholder="Folder Name" required class="modal-input"></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('folderModal')">Cancel</button><button type="button" class="btn btn-primary" onclick="submitNewFolder()">Create</button></div>
    </div>
</div>

<div id="mediaModal" class="modal">
    <div class="modal-content"><div class="modal-header"><span id="mediaTitle" class="modal-title">Media Viewer</span><span class="modal-close" onclick="closeModal('mediaModal')">&times;</span></div><div id="mediaBody" class="media-container"></div></div>
</div>

<div id="confirmModal" class="modal">
    <div class="modal-content" style="max-width: 400px;"><div class="modal-header"><span class="modal-title" id="confirmTitle">Confirm Action</span><span class="modal-close" onclick="closeModal('confirmModal')">&times;</span></div><div class="modal-body" id="confirmText"></div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button><button type="button" class="btn btn-danger" id="confirmOkBtn">Confirm</button></div></div>
</div>

<div id="promptModal" class="modal">
    <div class="modal-content" style="max-width: 400px;"><div class="modal-header"><span class="modal-title" id="promptTitle">Input Required</span><span class="modal-close" onclick="closeModal('promptModal')">&times;</span></div><div class="modal-body"><div id="promptText" style="margin-bottom:12px"></div><input type="text" id="promptInput" class="modal-input"></div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('promptModal')">Cancel</button><button type="button" class="btn btn-primary" id="promptOkBtn">Save</button></div></div>
</div>

<div id="alertModal" class="modal">
    <div class="modal-content" style="max-width: 400px;"><div class="modal-header"><span class="modal-title" id="alertTitle">Notice</span><span class="modal-close" onclick="closeModal('alertModal')">&times;</span></div><div class="modal-body" id="alertText"></div><div class="modal-footer"><button type="button" class="btn btn-primary" onclick="closeModal('alertModal')">OK</button></div></div>
</div>

<div id="contextMenu" class="context-menu">
    <div onclick="renamePrompt()">✏️ Rename</div>
    <div onclick="movePrompt()">📦 Move to folder</div>
</div>

<script>
/**
 * Single Page Application Logic (Vanilla JS AJAX)
 */

let currentDir = "<?= addslashes($relativeDir) ?>";
let currentSearch = "<?= addslashes($searchQuery) ?>";
let selectedPath = null;
let selectedName = null;
let currentXhr = null;
const menu = document.getElementById('contextMenu');

// Initial Load
window.onload = () => {
    renderExplorer(<?= json_encode($items) ?>, currentDir, currentSearch);
    updateStats(<?= json_encode(['usedPercent' => number_format($usedPercent, 2), 'used' => formatSize($totalUsed), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100]) ?>);
};

// Handle Browser Back/Forward
window.onpopstate = (e) => {
    const state = e.state || { dir: "", search: "" };
    fetchExplorer(state.dir, state.search, false);
};

async function fetchExplorer(dir, search = "", updateHistory = true) {
    const overlay = document.getElementById('loadingOverlay');
    overlay.style.display = 'flex';
    
    try {
        const params = new URLSearchParams({ dir, search, ajax: 1 });
        const response = await fetch(`?${params.toString()}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        currentDir = data.dir;
        currentSearch = data.search;
        
        renderExplorer(data.items, currentDir, currentSearch);
        updateStats(data.stats);
        
        if (updateHistory) {
            const url = new URL(window.location);
            url.searchParams.set('dir', currentDir);
            if (currentSearch) url.searchParams.set('search', currentSearch);
            else url.searchParams.delete('search');
            history.pushState({ dir: currentDir, search: currentSearch }, '', url);
        }
        
        document.getElementById('clearSearchBtn').style.display = currentSearch ? 'inline-flex' : 'none';
        document.getElementById('searchInput').value = currentSearch;
    } catch (e) {
        uiAlert("Failed to load folder content.");
    } finally {
        overlay.style.display = 'none';
    }
}

function renderExplorer(items, dir, search) {
    const list = document.getElementById('fileListContent');
    const breadcrumbTrail = document.getElementById('breadcrumbTrail');
    const itemCounter = document.getElementById('itemCounter');
    
    let bcHtml = "";
    if (search) {
        bcHtml = `🔍 Search results for "<strong>${escapeHtml(search)}</strong>"`;
    } else {
        bcHtml = `🏠 <a onclick="fetchExplorer('')">Root</a>`;
        const parts = dir.split('/').filter(p => p);
        let cum = "";
        parts.forEach(p => {
            cum += (cum ? '/' : '') + p;
            bcHtml += ` <span>/</span> <a onclick="fetchExplorer('${cum.replace(/'/g, "\\'")}')">${escapeHtml(p)}</a>`;
        });
    }
    breadcrumbTrail.innerHTML = bcHtml;
    itemCounter.innerText = `Showing ${items.length} item(s)`;

    let html = "";
    if (!search && dir !== "") {
        const parent = dir.split('/').slice(0, -1).join('/');
        html += `
            <div class="file-item">
                <div class="file-grid">
                    <div class="col"></div>
                    <div class="col">
                        <div class="col-info" onclick="fetchExplorer('${parent.replace(/'/g, "\\'")}')">
                            <span>⤴️</span> <strong>..</strong>
                        </div>
                    </div>
                    <div class="col col-date"></div><div class="col col-type"></div><div class="col col-size"></div><div class="col"></div>
                </div>
            </div>`;
    }

    items.forEach(f => {
        const isDir = f.isDir;
        const icon = isDir ? '📁' : '📄';
        const action = isDir 
            ? `onclick="fetchExplorer('${f.path.replace(/'/g, "\\'")}')"` 
            : `onclick="handleItemDblClickSelf('${f.path.replace(/'/g, "\\'")}', '${f.name.replace(/'/g, "\\'")}', '${f.type.toLowerCase()}')"`;
        
        html += `
            <div class="file-item" 
                 data-path="${escapeHtml(f.path)}" 
                 data-name="${escapeHtml(f.name)}" 
                 data-ext="${f.type.toLowerCase()}"
                 data-isdir="${isDir ? '1' : '0'}"
                 oncontextmenu="handleContextMenu(event, this)">
                <div class="file-grid">
                    <div class="col" style="text-align:center">
                        <input type="checkbox" name="selected_items[]" value="${escapeHtml(f.path)}" onclick="updateBulkBtn()">
                    </div>
                    <div class="col">
                        <div class="col-info" ${action}>
                            <span>${icon}</span>
                            <div>
                                <strong>${escapeHtml(f.name)}</strong>
                                ${search ? `<span class="path-label">in ${escapeHtml(f.path.substring(0, f.path.lastIndexOf('/')) || 'Root')}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="col col-date">${f.mtime}</div>
                    <div class="col col-type">${f.type}</div>
                    <div class="col col-size">${f.size}</div>
                    <div class="col col-actions">
                        ${!isDir ? `<a href="?download=${encodeURIComponent(f.path)}" class="icon-btn" title="Download">⬇️</a>` : ''}
                        <button type="button" onclick="deleteItem('${escapeHtml(f.path)}', '${escapeHtml(f.name)}')" class="icon-btn" title="Delete">🗑️</button>
                    </div>
                </div>
            </div>`;
    });

    if (items.length === 0) {
        html = `<div style="padding: 40px; text-align: center; color: #94a3b8;">${search ? 'No matches found.' : 'This folder is empty.'}</div>`;
    }
    
    list.innerHTML = html;
    document.getElementById('selectAll').checked = false;
    updateBulkBtn();
}

function updateStats(stats) {
    document.getElementById('statPercent').innerText = stats.usedPercent + '%';
    const bar = document.getElementById('statBar');
    bar.style.width = stats.usedPercent + '%';
    bar.className = 'storage-bar-fill ' + (parseFloat(stats.usedPercent) > 90 ? 'critical' : (parseFloat(stats.usedPercent) > 75 ? 'warning' : ''));
    document.getElementById('statUsedText').innerText = stats.used + ' / 50 GB';
    document.getElementById('statTotalFiles').innerText = 'Total Files: ' + stats.totalFiles;
    
    const dropdownBtn = document.getElementById('uploadDropdownBtn');
    if (dropdownBtn) dropdownBtn.disabled = stats.isFull;
}

/**
 * Dropdown UI logic
 */
function toggleUploadDropdown() {
    document.getElementById('uploadDropdownMenu').classList.toggle('active');
}

window.addEventListener('click', function(e) {
    if (!e.target.matches('#uploadDropdownBtn')) {
        const menu = document.getElementById('uploadDropdownMenu');
        if (menu && menu.classList.contains('active')) {
            menu.classList.remove('active');
        }
    }
});

/**
 * Drag & Drop Logic
 */
function handleDragOver(e) {
    e.preventDefault();
    document.getElementById('explorerBody').classList.add('drag-over');
}

function handleDragLeave(e) {
    e.preventDefault();
    document.getElementById('explorerBody').classList.remove('drag-over');
}

async function handleDrop(e) {
    e.preventDefault();
    document.getElementById('explorerBody').classList.remove('drag-over');
    
    const items = e.dataTransfer.items;
    if (!items) return;

    let queue = [];

    // Local function to traverse directories
    async function traverseFileTree(item, path = "") {
        if (item.isFile) {
            const file = await new Promise((resolve) => item.file(resolve));
            queue.push({ file, relativePath: path + file.name });
        } else if (item.isDirectory) {
            const dirReader = item.createReader();
            const entries = await new Promise((resolve) => {
                dirReader.readEntries(resolve);
            });
            for (let i = 0; i < entries.length; i++) {
                await traverseFileTree(entries[i], path + item.name + "/");
            }
        }
    }

    // Process all dropped entries
    for (let i = 0; i < items.length; i++) {
        const entry = items[i].webkitGetAsEntry();
        if (entry) {
            await traverseFileTree(entry);
        }
    }

    if (queue.length > 0) {
        processUploadQueue(queue);
    }
}

/**
 * Unified Sequential Upload Logic
 */
async function uploadItems(type) {
    const input = type === 'folder' ? document.getElementById('folderUploadInput') : document.getElementById('uploadInput');
    if (!input.files.length) return;
    
    const files = Array.from(input.files);
    let queue = files.map(f => ({
        file: f,
        relativePath: f.webkitRelativePath || f.name
    }));

    processUploadQueue(queue);
    input.value = ''; 
}

async function processUploadQueue(queue) {
    const statusGroup = document.getElementById('uploadStatusGroup');
    const badge = document.getElementById('uploadCountBadge');
    const progressBar = document.getElementById('progressBar');
    const progressInfo = document.getElementById('progressInfo');
    const uploadSizeBadge = document.getElementById('uploadSizeBadge');
    const cancelBtn = document.getElementById('cancelUploadBtn');

    statusGroup.style.display = 'flex';
    cancelBtn.style.display = 'inline-flex';

    for (let i = 0; i < queue.length; i++) {
        const item = queue[i];
        const formData = new FormData();
        formData.append('upload[]', item.file);
        
        // Always append relativePath if it looks like a path (has slash)
        if (item.relativePath.includes('/')) {
            formData.append('relativePath', item.relativePath);
        }

        const startTime = Date.now();
        const xhr = new XMLHttpRequest();
        currentXhr = xhr;

        const uploadPromise = new Promise((resolve, reject) => {
            badge.innerText = `Uploading Item ${i + 1} of ${queue.length}`;
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) {
                    const percent = (e.loaded / e.total) * 100;
                    const elapsed = (Date.now() - startTime) / 1000;
                    const speed = elapsed > 0 ? e.loaded / elapsed : 0;
                    
                    if (percent >= 100) {
                        progressBar.style.width = '100%';
                        progressInfo.innerText = `${item.file.name} (Finalizing...)`;
                    } else {
                        progressBar.style.width = percent + '%';
                        progressInfo.innerText = `${item.file.name} (${Math.round(percent)}% - ${formatBytes(speed)}/s)`;
                    }
                    uploadSizeBadge.innerText = `${formatBytes(e.loaded)} / ${formatBytes(e.total)}`;
                }
            });
            xhr.onreadystatechange = () => {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) resolve();
                            else reject(res.error || "Server error");
                        } catch(e) { reject("Response error"); }
                    } else {
                        reject("Status: " + xhr.status);
                    }
                }
            };
            xhr.open('POST', `?dir=${encodeURIComponent(currentDir)}&action=upload&ajax=1`, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        });

        try { 
            await uploadPromise; 
        } catch (e) { 
            if (currentXhr) {
                uiAlert(e, "Upload Error"); 
                break; 
            }
            return;
        }
    }
    
    statusGroup.style.display = 'none';
    cancelBtn.style.display = 'none';
    uploadSizeBadge.innerText = '';
    fetchExplorer(currentDir, currentSearch);
}

/**
 * Common Actions (Search, New Folder, Delete, etc.)
 */
function handleSearchKeyUp(e) { if (e.key === 'Enter') triggerSearch(); }
function triggerSearch() { fetchExplorer(currentDir, document.getElementById('searchInput').value.trim()); }
function clearSearch() { document.getElementById('searchInput').value = ""; fetchExplorer(currentDir, ""); }

async function performAction(formData) {
    try {
        const response = await fetch(`?dir=${encodeURIComponent(currentDir)}&ajax=1`, {
            method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const res = await response.json();
        if (res.success) fetchExplorer(currentDir, currentSearch);
        else uiAlert(res.error || "Action failed.");
    } catch (e) { uiAlert("Connection error."); }
}

function submitNewFolder() {
    const name = document.getElementById('newFolderName').value.trim();
    if (!name) return;
    const fd = new FormData(); fd.append('newfolder', name);
    performAction(fd); closeModal('folderModal');
    document.getElementById('newFolderName').value = "";
}

function submitBulkDelete() {
    const checked = document.querySelectorAll('input[name="selected_items[]"]:checked');
    uiConfirm(`Delete ${checked.length} items?`, () => {
        const fd = new FormData(); fd.append('bulk_delete', '1');
        checked.forEach(cb => fd.append('selected_items[]', cb.value));
        performAction(fd);
    });
}

function deleteItem(path, name) {
    uiConfirm(`Delete "${name}"?`, async () => {
        try {
            await fetch(`?delete=${encodeURIComponent(path)}&dir=${encodeURIComponent(currentDir)}&ajax=1`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            fetchExplorer(currentDir, currentSearch);
        } catch (e) { uiAlert("Delete failed."); }
    });
}

function renamePrompt() {
    uiPrompt('New name:', selectedName, (newName) => {
        if (newName && newName !== selectedName) {
            const fd = new FormData(); fd.append('rename_old', selectedPath); fd.append('rename_new', newName);
            performAction(fd);
        }
    });
}

function movePrompt() {
    uiPrompt('Target path (from root):', '', (target) => {
        if (target !== null && target !== '') {
            const fd = new FormData(); fd.append('move_file', selectedPath); fd.append('move_target', target);
            performAction(fd);
        }
    });
}

/**
 * UI Modals & Helpers
 */
function uiAlert(msg, title = "Notice") { document.getElementById('alertText').innerText = msg; document.getElementById('alertTitle').innerText = title; openModal('alertModal'); }
function uiConfirm(msg, onOk) { document.getElementById('confirmText').innerText = msg; const okBtn = document.getElementById('confirmOkBtn'); const newBtn = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(newBtn, okBtn); newBtn.onclick = () => { onOk(); closeModal('confirmModal'); }; openModal('confirmModal'); }
function uiPrompt(msg, def, onOk) { document.getElementById('promptText').innerText = msg; const input = document.getElementById('promptInput'); input.value = def; const okBtn = document.getElementById('promptOkBtn'); const newBtn = okBtn.cloneNode(true); okBtn.parentNode.replaceChild(newBtn, okBtn); newBtn.onclick = () => { onOk(input.value); closeModal('promptModal'); }; openModal('promptModal'); setTimeout(() => input.focus(), 300); }
function openModal(id) { const m = document.getElementById(id); m.style.display = 'flex'; setTimeout(() => m.classList.add('active'), 10); }
function closeModal(id) { 
    const m = document.getElementById(id); m.classList.remove('active'); 
    setTimeout(() => { 
        m.style.display = 'none'; 
        if (id === 'mediaModal') { const b = document.getElementById('mediaBody'); const m = b.querySelector('audio, video'); if (m) m.pause(); b.innerHTML = ''; } 
    }, 200); 
}

function handleItemDblClickSelf(path, name, ext) {
    const imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    const videoExts = ['mp4', 'webm', 'ogg'];
    const audioExts = ['mp3', 'wav', 'ogg'];
    const b = document.getElementById('mediaBody');
    const url = `?download=${encodeURIComponent(path)}&t=${Date.now()}`;
    document.getElementById('mediaTitle').innerText = name;
    b.innerHTML = '<div style="color:white">Loading...</div>';

    if (imgExts.includes(ext)) {
        const img = new Image(); img.onload = () => { b.innerHTML = ''; b.appendChild(img); }; img.src = url;
        openModal('mediaModal');
    } else if (videoExts.includes(ext)) {
        const v = document.createElement('video'); v.controls = true; v.autoplay = true; v.src = url;
        v.onloadeddata = () => { b.innerHTML = ''; b.appendChild(v); };
        openModal('mediaModal');
    } else if (audioExts.includes(ext)) {
        const a = document.createElement('audio'); a.controls = true; a.autoplay = true; a.src = url;
        a.onloadeddata = () => { b.innerHTML = ''; b.appendChild(a); };
        openModal('mediaModal');
    }
}

function escapeHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function formatBytes(b) { if (b === 0) return '0 Bytes'; const k = 1024; const s = ['Bytes', 'KB', 'MB', 'GB']; const i = Math.floor(Math.log(b) / Math.log(k)); return parseFloat((b / Math.pow(k, i)).toFixed(2)) + ' ' + s[i]; }
function toggleSelectAll(m) { document.getElementsByName('selected_items[]').forEach(cb => cb.checked = m.checked); updateBulkBtn(); }
function updateBulkBtn() { const c = document.querySelectorAll('input[name="selected_items[]"]:checked').length; const b = document.getElementById('bulkDeleteBtn'); b.disabled = c === 0; b.innerHTML = c > 0 ? `🗑️ Delete (${c})` : `🗑️ Delete`; }
function abortUpload() { if (currentXhr) { currentXhr.abort(); currentXhr = null; location.reload(); } }
function handleContextMenu(e, el) { e.preventDefault(); selectedPath = el.dataset.path; selectedName = el.dataset.name; menu.style.left = e.pageX + 'px'; menu.style.top = e.pageY + 'px'; menu.style.display = 'block'; }
document.addEventListener('click', () => menu.style.display = 'none');
window.onclick = (e) => { if (e.target.classList.contains('modal')) closeModal(e.target.id); };
</script>
</body>
</html>
<?php ob_end_flush(); ?>