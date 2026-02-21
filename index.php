<?php
/**
 * PHP File Explorer - Material Design Redesign (Full Feature Restoration)
 * Features: Login System, Recursive Search, 100GB Storage Monitor, Progress bar with Speed indicator, 
 * Sequential Upload, Selection Counter, Media Player Modal, Context Menus, ZIP Compression, 
 * Folder Upload & Drag-and-Drop Support, Multi-column Sorting, Server-side Pagination.
 */

ob_start();

// ================= CONFIG & PATHS =================
ini_set('display_errors', 0);
error_reporting(E_ALL);
@set_time_limit(0); 
@ini_set('memory_limit', '1024M');

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'files';
if (!file_exists($baseDir)) mkdir($baseDir, 0777, true);
$realBase = realpath($baseDir); // This is the absolute root for the admin
$storageLimit = 100 * 1024 * 1024 * 1024; // 100GB

// --- FORWARD DECLARATION OF HELPERS ---
function safePath($path, $realBase) {
    $realPath = realpath($path);
    if ($realPath === false) {
        $parent = realpath(dirname($path));
        return ($parent && strpos($parent, $realBase) === 0) ? $path : false;
    }
    return (strpos($realPath, $realBase) === 0) ? $realPath : false;
}

// ================= SHARE LINK HANDLING (BEFORE AUTH) =================
$is_shared_view = false;
$is_single_file_share = false;
$single_shared_file_info = null;
$share_token = $_GET['share'] ?? null;

if ($share_token && preg_match('/^[a-f0-9]{32}$/', $share_token)) {
    $sharesDir = __DIR__ . '/.shares';
    $shareFile = $sharesDir . '/' . $share_token;
    if (file_exists($shareFile)) {
        $shared_relative_path = trim(file_get_contents($shareFile));
        $shared_full_path = safePath($realBase . DIRECTORY_SEPARATOR . $shared_relative_path, $realBase);

        if ($shared_full_path && file_exists($shared_full_path)) {
            $is_shared_view = true;
            if (is_file($shared_full_path)) {
                $is_single_file_share = true;
                $baseDir = dirname($shared_full_path);
                $realBase = realpath($baseDir);
                $single_shared_file_info = $shared_full_path;
            } else { // It's a directory
                $baseDir = $shared_full_path; 
                $realBase = realpath($baseDir); 
            }
        }
    }
}


// ================= SESSION & AUTH (SKIPPED FOR SHARED VIEW) =================
if (!$is_shared_view) {
    session_start();
    $auth_user = "admin";
    $auth_pass = "manuelsintos21";

    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

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

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true): ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - File Explorer</title>
        <style>
            :root { --primary: #3f51b5; --bg: #f3f4f9; --text: #1c1b1f; --surface: #ffffff; }
            body { font-family: 'Roboto', system-ui, sans-serif; background: var(--bg); display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .login-card { background: var(--surface); padding: 2.5rem; border-radius: 28px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); width: 100%; max-width: 360px; text-align: center; }
            h2 { font-weight: 400; color: var(--primary); margin-bottom: 2rem; }
            input { width: 100%; padding: 14px; margin: 10px 0; border: 1px solid #c4c7c5; border-radius: 12px; box-sizing: border-box; font-size: 1rem; }
            input:focus { outline: 2px solid var(--primary); border-color: transparent; }
            button { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-weight: 500; font-size: 1rem; margin-top: 1.5rem; transition: 0.3s; }
            button:hover { box-shadow: 0 4px 12px rgba(63, 81, 181, 0.3); }
            .error { color: #b3261e; font-size: 0.85rem; margin-bottom: 1rem; padding: 10px; background: #f9dedc; border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h2>📂 Welcome</h2>
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
    <?php exit; endif;
} // end of auth check block
?>
<?php
// ================= CONFIG & LOGIC (CONTINUED) =================
$adminRealBase = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'files'); 

// --- PERFORMANCE OPTIMIZATION ---
$cacheFile = __DIR__ . '/.explorer_cache';
$globalIndexFile = __DIR__ . '/.index.json';

function invalidateCache($statsCacheFile, $globalIndexFile) {
    if (file_exists($statsCacheFile)) @unlink($statsCacheFile);
    if (file_exists($globalIndexFile)) @unlink($globalIndexFile);
}

function getGlobalIndex($realBase, $globalIndexFile) {
    if (file_exists($globalIndexFile)) {
        $data = json_decode(file_get_contents($globalIndexFile), true);
        if (is_array($data)) return $data;
    }
    
    $allItems = [];
    if (!is_dir($realBase)) return [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realBase, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $fileInfo) {
        $allItems[] = [
            'name' => $fileInfo->getFilename(),
            'path' => ltrim(str_replace($realBase, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR),
            'isDir' => $fileInfo->isDir(), 
            'mtime' => $fileInfo->getMTime(),
            'size' => $fileInfo->isDir() ? -1 : $fileInfo->getSize(),
            'type' => $fileInfo->isDir() ? 'Folder' : strtoupper(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION))
        ];
    }
    file_put_contents($globalIndexFile, json_encode($allItems));
    return $allItems;
}

function getDirectorySize($path) {
    $size = 0;
    if (!file_exists($path)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) $size += $file->getSize();
    return $size;
}

function getTotalFileCount($path) {
    $count = 0;
    if (!file_exists($path)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $count++;
    return $count;
}

function recursiveDelete($path) {
    if (is_dir($path)) {
        $files = array_diff(scandir($path), array('.', '..'));
        foreach ($files as $file) recursiveDelete($path . DIRECTORY_SEPARATOR . $file);
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

$reqDir = $_GET['dir'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_GET['ajax']);
$currentDir = safePath($baseDir . DIRECTORY_SEPARATOR . $reqDir, $realBase) ?: $realBase;
$relativeDir = ltrim(str_replace($realBase, '', $currentDir), DIRECTORY_SEPARATOR);


// Pagination & Sort Params
$page = (int)($_GET['page'] ?? 1);
$perPage = 50;
$sortKey = $_GET['sort'] ?? 'name';
$sortOrder = (int)($_GET['order'] ?? 1);

function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ================= ACTION HANDLERS (ADMIN ONLY) =================
if (!$is_shared_view) {
    // 1. UPLOAD HANDLER
    if (isset($_GET['action']) && $_GET['action'] === 'upload' && !empty($_FILES['upload'])) {
        $totalUsed = getDirectorySize($adminRealBase);
        $newFilesSize = array_sum($_FILES['upload']['size']);
        if (($totalUsed + $newFilesSize) > $storageLimit) {
            http_response_code(403);
            sendJsonResponse(['success' => false, 'error' => 'Storage limit exceeded.']);
        }
        $files = $_FILES['upload'];
        $successCount = 0;
        $relativePath = $_POST['relativePath'] ?? '';
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $name = basename($files['name'][$i]);
                if (!empty($relativePath)) {
                    $targetFile = $currentDir . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
                    $targetDir = dirname($targetFile);
                    if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
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
        if ($successCount > 0) invalidateCache($cacheFile, $globalIndexFile);
        sendJsonResponse(['success' => true, 'count' => $successCount]);
    }

    // 2. BULK OPERATIONS
    if (isset($_POST['bulk_delete']) && !empty($_POST['selected_items'])) {
        foreach ($_POST['selected_items'] as $itemPath) {
            $file = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
            if ($file && $file !== $realBase) recursiveDelete($file);
        }
        invalidateCache($cacheFile, $globalIndexFile);
        if ($isAjax) sendJsonResponse(['success' => true]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
        exit;
    }

    if (isset($_POST['bulk_move'], $_POST['move_target']) && !empty($_POST['selected_items'])) {
        $targetDir = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_target'], $realBase);
        $successCount = 0;
        $error = "";

        if ($targetDir && is_dir($targetDir)) {
            foreach ($_POST['selected_items'] as $itemPath) {
                $file = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
                if ($file) {
                    if (is_dir($file) && ($file === $targetDir || strpos($targetDir, $file . DIRECTORY_SEPARATOR) === 0)) continue;
                    $newPath = $targetDir . DIRECTORY_SEPARATOR . basename($file);
                    if ($file !== $newPath && !file_exists($newPath)) {
                        if (rename($file, $newPath)) $successCount++;
                    }
                }
            }
            if ($successCount > 0) invalidateCache($cacheFile, $globalIndexFile);
        } else {
            $error = "Invalid target directory.";
        }
        if ($isAjax) sendJsonResponse(['success' => $successCount > 0, 'moved' => $successCount, 'error' => $error]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir));
        exit;
    }

    if (isset($_POST['bulk_zip']) && !empty($_POST['selected_items'])) {
        if (!extension_loaded('zip')) die("ZIP extension is not enabled.");
        $zip = new ZipArchive();
        $zipName = 'download_' . date('Ymd_His') . '.zip';
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) die("Could not create ZIP.");
        foreach ($_POST['selected_items'] as $itemPath) {
            $fullPath = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
            if ($fullPath && file_exists($fullPath)) {
                if (is_dir($fullPath)) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $innerPath = substr($filePath, strlen(dirname($fullPath)) + 1);
                            $zip->addFile($filePath, str_replace(DIRECTORY_SEPARATOR, '/', $innerPath));
                        }
                    }
                } else {
                    $zip->addFile($fullPath, basename($fullPath));
                }
            }
        }
        $zip->close();
        if (file_exists($zipPath)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath); unlink($zipPath); exit;
        }
    }

    // 3. FILE OPERATIONS
    if (isset($_POST['action']) && $_POST['action'] === 'create_share') {
        $sharesDir = __DIR__ . '/.shares';
        if (!file_exists($sharesDir)) @mkdir($sharesDir, 0777, true);
        
        $path_to_share = $_POST['path'] ?? '';
        $real_path_to_share = safePath($adminRealBase . DIRECTORY_SEPARATOR . $path_to_share, $adminRealBase);
        if (!$real_path_to_share) sendJsonResponse(['success' => false, 'error' => 'Invalid path']);

        $existing_token = null;
        $files = glob($sharesDir . '/*');
        foreach($files as $file){
            if(is_file($file) && trim(file_get_contents($file)) === $path_to_share) {
                $existing_token = basename($file);
                break;
            }
        }

        if ($existing_token) sendJsonResponse(['success' => true, 'token' => $existing_token]);

        $token = bin2hex(random_bytes(16));
        file_put_contents($sharesDir . '/' . $token, $path_to_share);
        sendJsonResponse(['success' => true, 'token' => $token]);
    }


    if (!empty($_POST['newfolder'])) {
        $name = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $_POST['newfolder']);
        $success = false;
        if (trim($name)) {
            $newFolderPath = $currentDir . DIRECTORY_SEPARATOR . trim($name);
            if (!file_exists($newFolderPath)) {
                 if (@mkdir($newFolderPath, 0777, true)) {
                     $success = true;
                     invalidateCache($cacheFile, $globalIndexFile);
                 }
            }
        }
        if ($isAjax) sendJsonResponse(['success' => $success]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
    }

    if (isset($_GET['delete'])) {
        $file = safePath($realBase . DIRECTORY_SEPARATOR . $_GET['delete'], $realBase);
        if ($file && $file !== $realBase) {
            if (recursiveDelete($file)) invalidateCache($cacheFile, $globalIndexFile);
        }
        if ($isAjax) sendJsonResponse(['success' => true]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
    }

    if (isset($_POST['rename_old'], $_POST['rename_new'])) {
        $old = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['rename_old'], $realBase);
        $newName = basename($_POST['rename_new']);
        if ($old && $old !== $realBase && !empty($newName)) {
            if (rename($old, dirname($old) . DIRECTORY_SEPARATOR . $newName)) invalidateCache($cacheFile, $globalIndexFile);
        }
        if ($isAjax) sendJsonResponse(['success' => true]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
    }
    if (isset($_POST['move_file'], $_POST['move_target'])) {
        $file = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_file'], $realBase);
        $targetDir = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_target'], $realBase);
        if ($file && $targetDir && is_dir($targetDir)) {
            if (is_dir($file) && ($file === $targetDir || strpos($targetDir, $file . DIRECTORY_SEPARATOR) === 0)) {}
            else { if (rename($file, $targetDir . DIRECTORY_SEPARATOR . basename($file))) invalidateCache($cacheFile, $globalIndexFile); }
        }
        if ($isAjax) sendJsonResponse(['success' => true]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
    }
} // END ADMIN-ONLY ACTIONS

// --- UNIVERSAL DOWNLOADER (For admins and shared links) ---
if (isset($_GET['download'])) {
    $file_path_relative = $_GET['download'];
    // Resolve full path relative to the established baseDir
    $file = safePath($baseDir . DIRECTORY_SEPARATOR . $file_path_relative, $realBase);
    
    if ($file && is_file($file)) {
        while (ob_get_level()) ob_end_clean();
        $size = filesize($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
            'webp'=>'image/webp','svg'=>'image/svg+xml','mp4'=>'video/mp4','webm'=>'video/webm',
            'ogg'=>'video/ogg','mp3'=>'audio/mpeg','wav'=>'audio/wav','pdf' => 'application/pdf'
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        $disposition = isset($_GET['force']) || $is_shared_view ? 'attachment' : 'inline';
        
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($file) . '"');
        header('Accept-Ranges: bytes');
        
        if (isset($_SERVER['HTTP_RANGE']) && !$is_shared_view) {
            list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
            $range = explode('-', $range);
            $start = (int)$range[0];
            $end = (isset($range[1]) && is_numeric($range[1])) ? (int)$range[1] : $size - 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$size");
            $length = $end - $start + 1;
            header("Content-Length: " . $length);
            $fp = fopen($file, 'rb');
            fseek($fp, $start);
            echo fread($fp, $length);
            fclose($fp);
        } else {
            header("Content-Length: " . $size);
            readfile($file);
        }
        exit;
    }
}

// ================= DATA GATHERING =================
$allItems = [];
if ($is_single_file_share) {
    $fPath = $single_shared_file_info;
    $f = basename($fPath);
    $item_path_for_js = ltrim(str_replace($realBase, '', $fPath), DIRECTORY_SEPARATOR);
    $allItems[] = [
        'name' => $f, 'path' => $item_path_for_js,
        'isDir' => false, 'mtime' => filemtime($fPath),
        'mtime_f' => date("Y-m-d H:i", filemtime($fPath)),
        'size' => filesize($fPath),
        'size_f' => formatSize(filesize($fPath)),
        'type' => strtoupper(pathinfo($f, PATHINFO_EXTENSION))
    ];
} else {
    $isSearch = !empty($searchQuery) && !$is_shared_view; 
    if ($isSearch) {
        $allFiles = getGlobalIndex($realBase, $globalIndexFile);
        foreach ($allFiles as $item) {
            if (stripos($item['name'], $searchQuery) !== false) {
                $item['mtime_f'] = date("Y-m-d H:i", $item['mtime']);
                $item['size_f'] = $item['isDir'] ? '--' : formatSize($item['size']);
                $allItems[] = $item;
            }
        }
    } else {
        $scanned = @scandir($currentDir);
        if ($scanned) {
            foreach ($scanned as $f) {
                if ($f === '.' || $f === '..') continue;
                $fPath = $currentDir . DIRECTORY_SEPARATOR . $f;
                // The path sent to JS must be relative to the $realBase (admin root or share root)
                $item_path_for_js = ltrim(str_replace($realBase, '', $fPath), DIRECTORY_SEPARATOR);

                $allItems[] = [
                    'name' => $f, 'path' => $item_path_for_js,
                    'isDir' => is_dir($fPath), 'mtime' => filemtime($fPath),
                    'mtime_f' => date("Y-m-d H:i", filemtime($fPath)),
                    'size' => is_dir($fPath) ? -1 : filesize($fPath),
                    'size_f' => is_dir($fPath) ? '--' : formatSize(filesize($fPath)),
                    'type' => is_dir($fPath) ? 'Folder' : strtoupper(pathinfo($f, PATHINFO_EXTENSION))
                ];
            }
        }
    }
}

// SERVER SIDE SORTING
usort($allItems, function($a, $b) use ($sortKey, $sortOrder) {
    if ($a['isDir'] !== $b['isDir']) return $b['isDir'] - $a['isDir'];
    $valA = $a[$sortKey]; $valB = $b[$sortKey];
    if (is_string($valA)) return strnatcasecmp($valA, $valB) * $sortOrder;
    return ($valA - $valB) * $sortOrder;
});

// SERVER SIDE PAGINATION
$totalMatched = count($allItems);
if ($isSearch) {
    $offset = ($page - 1) * $perPage;
    $items = array_slice($allItems, $offset, $perPage);
} else {
    $items = $allItems;
}

// --- STATS (ADMIN ONLY) ---
$stats = [];
if (!$is_shared_view) {
    $cacheDuration = 300;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
        $stats = json_decode(file_get_contents($cacheFile), true);
    }
    if (empty($stats) || !isset($stats['size']) || !isset($stats['count'])) {
        $totalUsed = getDirectorySize($adminRealBase);
        $totalFilesStorage = getTotalFileCount($adminRealBase);
        $stats = ['size' => $totalUsed, 'count' => $totalFilesStorage];
        file_put_contents($cacheFile, json_encode($stats));
    } else {
        $totalUsed = $stats['size'];
        $totalFilesStorage = $stats['count'];
    }
    $usedPercent = ($totalUsed / $storageLimit) * 100;
}


if ($isAjax) {
    $response = [
        'dir' => $relativeDir, 'search' => $searchQuery, 'items' => $items,
        'page' => $page, 'totalCount' => $totalMatched, 'perPage' => $perPage,
    ];
    if (!$is_shared_view) {
       $response['stats'] = ['used' => formatSize($totalUsed), 'usedPercent' => number_format($usedPercent, 2), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100];
    }
    sendJsonResponse($response);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Explorer Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="index.css" />
</head>
<body>

    <?php if (!$is_shared_view): ?>
    <div class="sidebar-overlay" onclick="toggleSidebar(false)"></div>
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span>📂 Explorer</span>
            <div class="close-sidebar" onclick="toggleSidebar(false)">✕</div>
        </div>
        
        <div class="storage-card">
            <h3>Storage Used</h3>
            <div class="progress-track">
                <div id="statBar" class="progress-fill" style="width: 0%"></div>
            </div>
            <div class="storage-details">
                <span id="statUsedText">0 / 0</span>
                <span id="statPercent">0%</span>
            </div>
            <div id="statTotalFiles" style="font-size: 0.7rem; margin-top: 8px; color: #555;">Total Files: 0</div>
        </div>

        <nav style="display: flex; flex-direction: column; gap: 12px;">
            <button class="btn btn-tonal" onclick="openModal('folderModal'); toggleSidebar(false);">🆕 New Folder</button>
        </nav>

        <div class="sidebar-bottom">
            <a href="?logout=1" class="logout-btn">🚪 Logout</a>
        </div>
    </aside>
    <?php endif; ?>

    <!-- MAIN CONTENT -->
    <main class="main-content" id="dropZone">
        <header class="top-bar">
            <?php if (!$is_shared_view): ?><div class="menu-toggle" onclick="toggleSidebar(true)">☰</div><?php endif; ?>
            
            <?php if (!$is_shared_view): ?>
            <div class="search-field">
                🔍 <input type="text" id="searchInput" placeholder="Search files..." value="<?= htmlspecialchars($searchQuery) ?>" onkeyup="handleSearchKeyUp(event)">
                <button id="clearSearchBtn" style="background:none; border:none; cursor:pointer; display:none" onclick="clearSearch()">✖</button>
            </div>
            <?php else: ?>
            <div class="brand"><span>📂 Shared Files</span></div>
            <?php endif; ?>
            
            <?php if (!$is_shared_view): ?>
            <div class="action-buttons">
                <button id="uploadBtn" class="btn btn-primary" onclick="document.getElementById('uploadInput').click()">📤 <span>Upload Files</span></button>
                <input type="file" id="uploadInput" multiple style="display:none" onchange="uploadItems('file')">
                
                <button class="btn btn-outline" onclick="document.getElementById('folderInput').click()">📁 <span>Upload Folder</span></button>
                <input type="file" id="folderInput" webkitdirectory style="display:none" onchange="uploadItems('folder')">
            </div>
            <?php endif; ?>
        </header>

        <!-- STICKY SUB-HEADER -->
        <div class="breadcrumb-bar">
            <div class="breadcrumb-container">
                <div class="breadcrumb-trail" id="breadcrumbTrail"></div>
                <div class="breadcrumb-actions">
                    <?php if (!$is_shared_view): ?>
                    <div id="bulkActions" class="bulk-actions-group" style="display: none;">
                        <button class="btn btn-tonal-danger" id="bulkDeleteBtn" onclick="submitBulkDelete()">🗑️ Delete</button>
                        <button class="btn btn-tonal-primary" id="bulkMoveBtn" onclick="movePrompt()">➡️ Move</button>
                        <button class="btn btn-tonal-primary" id="bulkZipBtn" onclick="submitBulkZip()">📦 ZIP</button>
                    </div>
                    <?php endif; ?>
                    <div class="status-controls">
                        <div id="paginationContainer" class="pagination-group" style="display:none;">
                            <button class="pagination-btn" id="prevPageBtn" onclick="navigatePage(-1)">❮</button>
                            <span class="page-indicator" id="pageIndicator">1</span>
                            <button class="pagination-btn" id="nextPageBtn" onclick="navigatePage(1)">❯</button>
                        </div>
                        <div class="item-counter" id="itemCounter">0 items</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="explorer-body">
            <?php if (!$is_shared_view): ?><div class="drop-hint">🚀 Drop here to upload</div><?php endif; ?>
            
            <?php if (!$is_shared_view): ?>
            <div id="uploadStatusCard" class="upload-status-card">
                <div class="upload-info">
                    <div id="uploadCountBadge" style="font-weight: 500; font-size: 0.85rem;">Uploading...</div>
                    <div class="upload-progress-container"><div id="uploadProgressBar"></div></div>
                    <div id="progressInfo" style="font-size: 0.75rem; color: #666; display: flex; justify-content: space-between; white-space: nowrap; overflow: hidden;">
                        <span id="uploadFileName" style="overflow: hidden; text-overflow: ellipsis; padding-right: 8px;">Waiting...</span>
                        <span id="uploadSpeed">0 KB/s</span>
                    </div>
                    <div id="uploadSizeBadge" style="font-size: 0.7rem; color: #888; margin-top: 4px; font-weight: 500;">0 / 0</div>
                </div>
                <button class="btn btn-outline" style="color: var(--danger); padding: 8px 12px; font-size: 0.75rem;" onclick="abortUpload()">Abort</button>
            </div>
            <?php endif; ?>

            <div class="data-table-container">
                <div class="table-header">
                    <?php if (!$is_shared_view): ?><div style="text-align:center"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></div><?php else: ?><div style="width:56px"></div><?php endif; ?>
                    <div style="cursor:pointer" onclick="changeSort('name')">Name</div>
                    <div class="col-date" style="cursor:pointer" onclick="changeSort('mtime')">Modified</div>
                    <div class="col-type" style="cursor:pointer" onclick="changeSort('type')">Type</div>
                    <div class="col-size" style="cursor:pointer" onclick="changeSort('size')">Size</div>
                    <div style="text-align:right">Actions</div>
                </div>
                <div id="fileListContent"></div>
            </div>
        </div>
    </main>
    
    <!-- MODALS -->
    <div id="mediaModal" class="modal"><div class="modal-content"><div class="media-header"><div style="display: flex; flex-direction: column; overflow: hidden; gap: 4px;"><span id="mediaTitle">Viewer</span><span id="mediaSubtitle">Preview</span></div><span onclick="closeModal('mediaModal')" style="cursor:pointer; font-size:32px; line-height: 1;">&times;</span></div><div id="mediaBody"><button class="nav-btn nav-prev" onclick="navigateMedia(-1)">❮</button><div id="mediaContainer"></div><button class="nav-btn nav-next" onclick="navigateMedia(1)">❯</button></div></div></div>
    
    <?php if (!$is_shared_view): ?>
    <div id="folderModal" class="modal"><div class="modal-content"><div class="modal-header">New Folder</div><div class="modal-body"><input type="text" id="newFolderName" class="m3-input" placeholder="Folder Name" autofocus></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('folderModal')">Cancel</button><button class="btn btn-primary" onclick="submitNewFolder()">Create</button></div></div></div>
    <div id="confirmModal" class="modal"><div class="modal-content"><div class="modal-header" id="confirmTitle">Confirm</div><div class="modal-body" id="confirmText"></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button><button class="btn btn-primary" id="confirmOkBtn" style="background: var(--danger)">Delete</button></div></div></div>
    <div id="promptModal" class="modal"><div class="modal-content"><div class="modal-header" id="promptTitle">Input</div><div class="modal-body"><div id="promptText" style="margin-bottom:12px;"></div><input type="text" id="promptInput" class="m3-input"></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('promptModal')">Cancel</button><button class="btn btn-primary" id="promptOkBtn">Confirm</button></div></div></div>
    <div id="alertModal" class="modal"><div class="modal-content"><div class="modal-header" id="alertTitle">Notice</div><div class="modal-body" id="alertText"></div><div class="modal-footer"><button class="btn btn-primary" onclick="closeModal('alertModal')">OK</button></div></div></div>
    <div id="shareModal" class="modal"><div class="modal-content"><div class="modal-header">Share Link</div><div class="modal-body"><p style="font-size:0.9rem; color: var(--on-surface-variant); margin-top:0; margin-bottom:1rem;">Anyone with this link can view and download.</p><div style="display:flex; gap:8px;"><input type="text" id="shareLinkInput" class="m3-input" readonly><button class="btn btn-primary" id="copyShareBtn" onclick="copyShareLink()">Copy</button></div></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('shareModal')">Done</button></div></div></div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu">
        <div onclick="sharePrompt()">🔗 Share link</div>
        <div onclick="renamePrompt()">✏️ Rename</div>
        <div onclick="movePrompt()">📦 Move this file</div>
    </div>
    <?php endif; ?>

    <script src="index.js?v=<?= @filemtime('index.js') ?: time() ?>"></script>
    <script>
    // Initialize the JS with data from PHP
    isSharedView = <?= $is_shared_view ? 'true' : 'false' ?>;
    isSingleFileShare = <?= $is_single_file_share ? 'true' : 'false' ?>;
    sharedFolderName = "<?= $is_shared_view ? basename($realBase) : '' ?>";
    currentDir = "<?= addslashes($relativeDir) ?>";
    currentSearch = "<?= addslashes($searchQuery) ?>";
    currentItems = <?= json_encode($items) ?>;
    totalItems = <?= $totalMatched ?>;
    currentPage = <?= $page ?>;
    sortKey = '<?= addslashes($sortKey) ?>';
    sortOrder = <?= $sortOrder ?>;
    
    window.onload = () => {
        if (!isSharedView) setupDragAndDrop();
        renderExplorer();
        <?php if (!$is_shared_view): ?>
        updateStats(<?= json_encode(['usedPercent' => number_format($usedPercent, 2), 'used' => formatSize($totalUsed), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100]) ?>);
        <?php endif; ?>
    };
    </script>
</body>
</html>
<?php ob_end_flush(); ?>