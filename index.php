<?php
/**
 * PHP File Explorer - Material Design Redesign (Full Feature Restoration)
 * Features: Login System, Recursive Search, 100GB Storage Monitor, Progress bar with Speed indicator, 
 * Sequential Upload, Selection Counter, Media Player Modal, Context Menus, ZIP Compression, 
 * Folder Upload & Drag-and-Drop Support, Multi-column Sorting, Server-side Pagination.
 */

ob_start();
session_start();

// ================= SESSION & AUTH =================
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
<?php exit; endif; ?>

<?php
// ================= CONFIG & LOGIC =================
ini_set('display_errors', 0);
error_reporting(E_ALL);
@set_time_limit(0); 
@ini_set('memory_limit', '1024M');

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'files';
if (!file_exists($baseDir)) mkdir($baseDir, 0777, true);
$realBase = realpath($baseDir);
$storageLimit = 100 * 1024 * 1024 * 1024; // 100GB

// --- PERFORMANCE OPTIMIZATION ---
$cacheFile = __DIR__ . '/.explorer_cache';
$searchCacheDir = __DIR__ . '/.explorer_search_cache';
if (!file_exists($searchCacheDir)) @mkdir($searchCacheDir, 0777, true);
$cacheDuration = 300; // Cache stats for 5 minutes

function invalidateCache($statsCacheFile, $searchCacheDir) {
    if (file_exists($statsCacheFile)) {
        @unlink($statsCacheFile);
    }
    // Clear all search cache files on any filesystem modification
    if (is_dir($searchCacheDir)) {
        $files = glob($searchCacheDir . '/*');
        foreach($files as $file){
            if(is_file($file)) {
                @unlink($file);
            }
        }
    }
}
// --- END OPTIMIZATION ---

function safePath($path, $realBase) {
    $realPath = realpath($path);
    if ($realPath === false) {
        $parent = realpath(dirname($path));
        return ($parent && strpos($parent, $realBase) === 0) ? $path : false;
    }
    return (strpos($realPath, $realBase) === 0) ? $realPath : false;
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
$currentDir = safePath($realBase . DIRECTORY_SEPARATOR . $reqDir, $realBase) ?: $realBase;
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

// 1. UPLOAD HANDLER
if (isset($_GET['action']) && $_GET['action'] === 'upload' && !empty($_FILES['upload'])) {
    $totalUsed = getDirectorySize($realBase); // Check current size before upload
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
    if ($successCount > 0) invalidateCache($cacheFile, $searchCacheDir);
    sendJsonResponse(['success' => true, 'count' => $successCount]);
}

// 2. BULK OPERATIONS
if (isset($_POST['bulk_delete']) && !empty($_POST['selected_items'])) {
    foreach ($_POST['selected_items'] as $itemPath) {
        $file = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
        if ($file && $file !== $realBase) recursiveDelete($file);
    }
    invalidateCache($cacheFile, $searchCacheDir);
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
                // Safety check: Don't move a directory into itself or a subdirectory of itself.
                if (is_dir($file) && ($file === $targetDir || strpos($targetDir, $file . DIRECTORY_SEPARATOR) === 0)) {
                    continue;
                }
                
                $newPath = $targetDir . DIRECTORY_SEPARATOR . basename($file);
                if ($file !== $newPath && !file_exists($newPath)) {
                    if (rename($file, $newPath)) {
                        $successCount++;
                    }
                }
            }
        }
        if ($successCount > 0) {
            invalidateCache($cacheFile, $searchCacheDir);
        }
    } else {
        $error = "Invalid target directory.";
    }
    
    if ($isAjax) {
        sendJsonResponse(['success' => $successCount > 0, 'moved' => $successCount, 'error' => $error]);
    }
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
if (!empty($_POST['newfolder'])) {
    $name = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $_POST['newfolder']);
    $success = false;
    if (trim($name)) {
        $newFolderPath = $currentDir . DIRECTORY_SEPARATOR . trim($name);
        if (!file_exists($newFolderPath)) {
             if (@mkdir($newFolderPath, 0777, true)) {
                 $success = true;
                 invalidateCache($cacheFile, $searchCacheDir);
             }
        }
    }
    if ($isAjax) sendJsonResponse(['success' => $success]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}

if (isset($_GET['delete'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_GET['delete'], $realBase);
    if ($file && $file !== $realBase) {
        if (recursiveDelete($file)) invalidateCache($cacheFile, $searchCacheDir);
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}

if (isset($_POST['rename_old'], $_POST['rename_new'])) {
    $old = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['rename_old'], $realBase);
    $newName = basename($_POST['rename_new']);
    if ($old && $old !== $realBase && !empty($newName)) {
        if (rename($old, dirname($old) . DIRECTORY_SEPARATOR . $newName)) invalidateCache($cacheFile, $searchCacheDir);
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}
if (isset($_POST['move_file'], $_POST['move_target'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_file'], $realBase);
    $targetDir = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_target'], $realBase);
    if ($file && $targetDir && is_dir($targetDir)) {
        // Safety check: Don't move a directory into itself or a subdirectory of itself.
        if (is_dir($file) && ($file === $targetDir || strpos($targetDir, $file . DIRECTORY_SEPARATOR) === 0)) {
            // Error
        } else {
            if (rename($file, $targetDir . DIRECTORY_SEPARATOR . basename($file))) {
                invalidateCache($cacheFile, $searchCacheDir);
            }
        }
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}

if (isset($_GET['download'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_GET['download'], $realBase);
    if ($file && is_file($file)) {
        while (ob_get_level()) ob_end_clean();
        $size = filesize($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
            'webp'=>'image/webp','svg'=>'image/svg+xml','mp4'=>'video/mp4','webm'=>'video/webm',
            'ogg'=>'video/ogg','mp3'=>'audio/mpeg','wav'=>'audio/wav'
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        $disposition = isset($_GET['force']) ? 'attachment' : 'inline';
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($file) . '"');
        header('Accept-Ranges: bytes');
        if (isset($_SERVER['HTTP_RANGE'])) {
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
$isSearch = !empty($searchQuery);
if ($isSearch) {
    // --- SEARCH CACHING LOGIC ---
    $searchCacheKey = md5(strtolower($searchQuery));
    $searchCacheFile = $searchCacheDir . DIRECTORY_SEPARATOR . $searchCacheKey;

    if (file_exists($searchCacheFile)) {
        // Load from cache
        $allItems = json_decode(file_get_contents($searchCacheFile), true);
    } else {
        // Perform search and cache results
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realBase, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fileInfo) {
            if (stripos($fileInfo->getFilename(), $searchQuery) !== false) {
                $allItems[] = [
                    'name' => $fileInfo->getFilename(),
                    'path' => ltrim(str_replace($realBase, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR),
                    'isDir' => $fileInfo->isDir(),
                    'mtime' => $fileInfo->getMTime(),
                    'mtime_f' => date("Y-m-d H:i", $fileInfo->getMTime()),
                    'size' => $fileInfo->isDir() ? -1 : $fileInfo->getSize(),
                    'size_f' => $fileInfo->isDir() ? '--' : formatSize($fileInfo->getSize()),
                    'type' => $fileInfo->isDir() ? 'Folder' : strtoupper(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION))
                ];
            }
        }
        // Save to cache
        file_put_contents($searchCacheFile, json_encode($allItems));
    }
} else {
    $scanned = @scandir($currentDir);
    if ($scanned) {
        foreach ($scanned as $f) {
            if ($f === '.' || $f === '..') continue;
            $fPath = $currentDir . DIRECTORY_SEPARATOR . $f;
            $allItems[] = [
                'name' => $f,
                'path' => ltrim($relativeDir . '/' . $f, '/'),
                'isDir' => is_dir($fPath),
                'mtime' => filemtime($fPath),
                'mtime_f' => date("Y-m-d H:i", filemtime($fPath)),
                'size' => is_dir($fPath) ? -1 : filesize($fPath),
                'size_f' => is_dir($fPath) ? '--' : formatSize(filesize($fPath)),
                'type' => is_dir($fPath) ? 'Folder' : strtoupper(pathinfo($f, PATHINFO_EXTENSION))
            ];
        }
    }
}

// SERVER SIDE SORTING
usort($allItems, function($a, $b) use ($sortKey, $sortOrder) {
    // Folders always first
    if ($a['isDir'] !== $b['isDir']) return $b['isDir'] - $a['isDir'];
    
    $valA = $a[$sortKey];
    $valB = $b[$sortKey];
    
    if (is_string($valA)) {
        // USE strnatcasecmp for natural sorting (e.g. 1, 2, 10 instead of 1, 10, 2)
        return strnatcasecmp($valA, $valB) * $sortOrder;
    }
    return ($valA - $valB) * $sortOrder;
});

// SERVER SIDE PAGINATION (Conditional: Only during search)
$totalMatched = count($allItems);
if ($isSearch) {
    $offset = ($page - 1) * $perPage;
    $items = array_slice($allItems, $offset, $perPage);
} else {
    $items = $allItems;
}

// --- OPTIMIZATION: Fetch stats from cache or recalculate ---
$stats = [];
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
    $stats = json_decode(file_get_contents($cacheFile), true);
}

if (empty($stats) || !isset($stats['size']) || !isset($stats['count'])) {
    $totalUsed = getDirectorySize($realBase);
    $totalFilesStorage = getTotalFileCount($realBase);
    $stats = ['size' => $totalUsed, 'count' => $totalFilesStorage];
    file_put_contents($cacheFile, json_encode($stats));
} else {
    $totalUsed = $stats['size'];
    $totalFilesStorage = $stats['count'];
}
$usedPercent = ($totalUsed / $storageLimit) * 100;

if ($isAjax) {
    sendJsonResponse([
        'dir' => $relativeDir, 'search' => $searchQuery, 'items' => $items,
        'page' => $page, 'totalCount' => $totalMatched, 'perPage' => $perPage,
        'stats' => ['used' => formatSize($totalUsed), 'usedPercent' => number_format($usedPercent, 2), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100]
    ]);
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

    <!-- MAIN CONTENT -->
    <main class="main-content" id="dropZone">
        <header class="top-bar">
            <div class="menu-toggle" onclick="toggleSidebar(true)">☰</div>

            <div class="search-field">
                🔍 <input type="text" id="searchInput" placeholder="Search files..." value="<?= htmlspecialchars($searchQuery) ?>" onkeyup="handleSearchKeyUp(event)">
                <button id="clearSearchBtn" style="background:none; border:none; cursor:pointer; display:none" onclick="clearSearch()">✖</button>
            </div>

            <div class="action-buttons">
                <button id="uploadBtn" class="btn btn-primary" onclick="document.getElementById('uploadInput').click()">📤 <span>Upload Files</span></button>
                <input type="file" id="uploadInput" multiple style="display:none" onchange="uploadItems('file')">
                
                <button class="btn btn-outline" onclick="document.getElementById('folderInput').click()">📁 <span>Upload Folder</span></button>
                <input type="file" id="folderInput" webkitdirectory style="display:none" onchange="uploadItems('folder')">
            </div>
        </header>

        <!-- STICKY SUB-HEADER: Redesigned and fixed at top -->
        <div class="breadcrumb-bar">
            <div class="breadcrumb-container">
                <div class="breadcrumb-trail" id="breadcrumbTrail"></div>
                <div class="breadcrumb-actions">
                    <div id="bulkActions" class="bulk-actions-group" style="display: none;">
                        <button class="btn btn-tonal-danger" id="bulkDeleteBtn" onclick="submitBulkDelete()">🗑️ Delete</button>
                        <button class="btn btn-tonal-primary" id="bulkMoveBtn" onclick="movePrompt()">➡️ Move</button>
                        <button class="btn btn-tonal-primary" id="bulkZipBtn" onclick="submitBulkZip()">📦 ZIP</button>
                    </div>
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
            <div class="drop-hint">🚀 Drop here to upload</div>
            
            <!-- Sequential Upload Status -->
            <div id="uploadStatusCard" class="upload-status-card">
                <div class="upload-info">
                    <div id="uploadCountBadge" style="font-weight: 500; font-size: 0.85rem;">Uploading...</div>
                    <div class="upload-progress-container">
                        <div id="uploadProgressBar"></div>
                    </div>
                    <!-- Upload Details Row -->
                    <div id="progressInfo" style="font-size: 0.75rem; color: #666; display: flex; justify-content: space-between; white-space: nowrap; overflow: hidden;">
                        <span id="uploadFileName" style="overflow: hidden; text-overflow: ellipsis; padding-right: 8px;">Waiting...</span>
                        <span id="uploadSpeed">0 KB/s</span>
                    </div>
                    <!-- Restoration: Uploaded Size Badge -->
                    <div id="uploadSizeBadge" style="font-size: 0.7rem; color: #888; margin-top: 4px; font-weight: 500;">0 / 0</div>
                </div>
                <button class="btn btn-outline" style="color: var(--danger); padding: 8px 12px; font-size: 0.75rem;" onclick="abortUpload()">Abort</button>
            </div>

            <div class="data-table-container">
                <div class="table-header">
                    <div style="text-align:center"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></div>
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

    <!-- M3 FOLDER MODAL -->
    <div id="folderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">New Folder</div>
            <div class="modal-body">
                <input type="text" id="newFolderName" class="m3-input" placeholder="Folder Name" autofocus>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('folderModal')">Cancel</button>
                <button class="btn btn-primary" onclick="submitNewFolder()">Create</button>
            </div>
        </div>
    </div>
    
    <!-- REDESIGNED CINEMATIC MEDIA MODAL -->
    <div id="mediaModal" class="modal">
        <div class="modal-content">
            <div class="media-header">
                <div style="display: flex; flex-direction: column; overflow: hidden; gap: 4px;">
                    <span id="mediaTitle" style="font-weight: 500; font-size: 1.1rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; letter-spacing: -0.2px;">Viewer</span>
                    <span id="mediaSubtitle" style="font-size: 0.75rem; opacity: 0.6; text-transform: uppercase; letter-spacing: 0.5px;">Media Preview</span>
                </div>
                <span onclick="closeModal('mediaModal')" style="cursor:pointer; font-size:32px; line-height: 1; padding: 4px; opacity: 0.8;">&times;</span>
            </div>
            <div id="mediaBody">
                <button class="nav-btn nav-prev" onclick="navigateMedia(-1)">❮</button>
                <div id="mediaContainer"></div>
                <button class="nav-btn nav-next" onclick="navigateMedia(1)">❯</button>
            </div>
        </div>
    </div>

    <!-- OTHER MODALS -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="confirmTitle">Confirm</div>
            <div class="modal-body" id="confirmText"></div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button>
                <button class="btn btn-primary" id="confirmOkBtn" style="background: var(--danger)">Delete</button>
            </div>
        </div>
    </div>

    <div id="promptModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="promptTitle">Input</div>
            <div class="modal-body">
                <div id="promptText" style="margin-bottom:12px; color: var(--on-surface-variant); font-size: 0.9rem;"></div>
                <input type="text" id="promptInput" class="m3-input">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('promptModal')">Cancel</button>
                <button class="btn btn-primary" id="promptOkBtn">Confirm</button>
            </div>
        </div>
    </div>

    <div id="alertModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="alertTitle">Notice</div>
            <div class="modal-body" id="alertText" style="font-size: 0.9rem;"></div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeModal('alertModal')">OK</button>
            </div>
        </div>
    </div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu">
        <div onclick="renamePrompt()">✏️ Rename</div>
        <div onclick="movePrompt()">📦 Move this file</div>
    </div>

    <script src="index.js"></script>
    <script>
    // Initialize the JS with data from PHP
    currentDir = "<?= addslashes($relativeDir) ?>";
    currentSearch = "<?= addslashes($searchQuery) ?>";
    currentItems = <?= json_encode($items) ?>;
    totalItems = <?= $totalMatched ?>;
    currentPage = <?= $page ?>;
    sortKey = '<?= addslashes($sortKey) ?>';
    sortOrder = <?= $sortOrder ?>;
    
    // Initial render
    window.onload = () => {
        setupDragAndDrop();
        renderExplorer();
        updateStats(<?= json_encode(['usedPercent' => number_format($usedPercent, 2), 'used' => formatSize($totalUsed), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100]) ?>);
    };
    </script>
</body>
</html>
<?php ob_end_flush(); ?>