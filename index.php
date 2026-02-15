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
    $totalUsed = getDirectorySize($realBase);
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
    sendJsonResponse(['success' => true, 'count' => $successCount]);
}

// 2. BULK OPERATIONS
if (isset($_POST['bulk_delete']) && !empty($_POST['selected_items'])) {
    foreach ($_POST['selected_items'] as $itemPath) {
        $file = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
        if ($file && $file !== $realBase) recursiveDelete($file);
    }
    if ($isAjax) sendJsonResponse(['success' => true]);
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
             }
        }
    }
    if ($isAjax) sendJsonResponse(['success' => $success]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}

if (isset($_GET['delete'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_GET['delete'], $realBase);
    if ($file && $file !== $realBase) recursiveDelete($file);
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}

if (isset($_POST['rename_old'], $_POST['rename_new'])) {
    $old = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['rename_old'], $realBase);
    $newName = basename($_POST['rename_new']);
    if ($old && $old !== $realBase && !empty($newName)) rename($old, dirname($old) . DIRECTORY_SEPARATOR . $newName);
    if ($isAjax) sendJsonResponse(['success' => true]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($relativeDir)); exit;
}
if (isset($_POST['move_file'], $_POST['move_target'])) {
    $file = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_file'], $realBase);
    $targetDir = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_target'], $realBase);
    if ($file && $targetDir && is_dir($targetDir)) rename($file, $targetDir . DIRECTORY_SEPARATOR . basename($file));
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
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
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
    // UPDATED: Include directories in recursive search using SELF_FIRST mode
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
        return strcasecmp($valA, $valB) * $sortOrder;
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

$totalUsed = getDirectorySize($realBase);
$totalFilesStorage = getTotalFileCount($realBase);
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
    <style>
        :root {
            --primary: #3f51b5;
            --on-primary: #ffffff;
            --secondary: #009688;
            --bg: #f8f9fd;
            --surface: #ffffff;
            --on-surface: #1c1b1f;
            --on-surface-variant: #49454f;
            --outline: #cac4d0;
            --danger: #b3261e;
            --sidebar-width: 280px;
            --elevation-1: 0 1px 3px 1px rgba(0,0,0,0.15);
            --elevation-2: 0 2px 6px 2px rgba(0,0,0,0.15);
            --m3-radius: 28px;
        }

        body { font-family: 'Roboto', sans-serif; background: var(--bg); color: var(--on-surface); margin: 0; display: flex; height: 100vh; overflow: hidden; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--surface);
            border-right: 1px solid var(--outline);
            display: flex;
            flex-direction: column;
            padding: 24px;
            box-sizing: border-box;
            flex-shrink: 0;
            z-index: 1001; /* Above main content on mobile */
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .brand { font-size: 1.5rem; font-weight: 500; color: var(--primary); margin-bottom: 32px; display: flex; align-items: center; justify-content: space-between; }
        .close-sidebar { display: none; cursor: pointer; font-size: 1.2rem; padding: 4px; }

        .storage-card {
            background: #e8eaf6;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 24px;
        }
        .storage-card h3 { margin: 0 0 12px 0; font-size: 0.85rem; text-transform: uppercase; color: var(--primary); letter-spacing: 0.5px; }
        .progress-track { background: #d1d9ff; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 8px; }
        .progress-fill { height: 100%; background: var(--primary); transition: width 0.5s ease; }
        .progress-fill.warning { background: #f59e0b; }
        .progress-fill.critical { background: var(--danger); }
        .storage-details { font-size: 0.75rem; color: var(--on-surface-variant); display: flex; justify-content: space-between; }

        .sidebar-bottom { margin-top: auto; }
        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--danger);
            padding: 12px 16px;
            border-radius: 100px;
            background: #f9dedc;
            font-weight: 500;
            transition: 0.2s;
        }
        .logout-btn:hover { background: #f2b8b5; }

        /* Content Area */
        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; width: 100%; }
        .main-content.drag-over { background-color: #f0f7ff; }

        .top-bar {
            background: var(--surface);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--outline);
            gap: 16px;
            z-index: 10;
        }

        .menu-toggle { display: none; cursor: pointer; font-size: 1.5rem; color: var(--primary); flex-shrink: 0; }

        .search-field {
            background: #f1f3f4;
            border-radius: 28px;
            padding: 6px 16px;
            display: flex;
            align-items: center;
            flex-grow: 1;
            max-width: 600px;
        }
        .search-field input { border: none; background: transparent; width: 100%; padding: 8px; outline: none; font-size: 1rem; }

        .action-buttons { display: flex; gap: 8px; flex-shrink: 0; }

        .btn {
            border: none;
            border-radius: 100px;
            padding: 8px 20px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-tonal { background: #e8def8; color: #1d192b; }
        .btn-outline { border: 1px solid var(--outline); background: transparent; color: var(--primary); }
        .btn:hover { box-shadow: var(--elevation-1); filter: brightness(0.95); }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .explorer-body { padding: 16px; overflow-y: auto; flex-grow: 1; position: relative; }
        .drop-hint { display: none; position: absolute; inset: 0; background: rgba(37, 99, 235, 0.05); pointer-events: none; z-index: 10; align-items: center; justify-content: center; font-weight: bold; color: var(--primary); border: 2px dashed var(--primary); margin: 24px; border-radius: 16px; }
        .main-content.drag-over .drop-hint { display: flex; }

        /* Sequential Upload Status */
        .upload-status-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: var(--elevation-1);
            display: none;
            align-items: center;
 gap: 16px;
        }
        .upload-info { flex-grow: 1; overflow: hidden; }
        .upload-progress-container { background: #eee; height: 10px; border-radius: 6px; overflow: hidden; margin: 8px 0; position: relative; }
        #uploadProgressBar { height: 100%; background: var(--primary); width: 0%; transition: width 0.1s linear; }
        #uploadProgressText { display: none; }

        /* Data Table */
        .data-table-container { background: var(--surface); border-radius: 16px; box-shadow: var(--elevation-1); overflow: hidden; }
        .table-header {
            display: grid;
            grid-template-columns: 44px 1fr 180px 100px 100px 110px;
            background: #fbfbfc;
            border-bottom: 1px solid var(--outline);
            padding: 10px 12px;
            font-weight: 500;
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            user-select: none;
        }
        .table-row {
            display: grid;
            grid-template-columns: 44px 1fr 180px 100px 100px 110px;
            align-items: center;
            padding: 8px 12px;
            border-bottom: 1px solid #f1f1f1;
            transition: 0.1s;
            cursor: default;
        }
        .table-row:hover { background: #f6f6f6; }
        .table-row.selected { background: #e8f0fe !important; } /* Highlight for selected row */
        .table-row.folder { cursor: pointer; }
        .col-name { display: flex; align-items: center; gap: 10px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .icon { font-size: 1.3rem; flex-shrink: 0; }

        /* Breadcrumbs */
        .breadcrumb-container { margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 0.85rem; }
        .breadcrumb-trail { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .breadcrumb-trail a { color: var(--primary); text-decoration: none; font-weight: 500; cursor: pointer; }
        .breadcrumb-trail span { color: var(--on-surface-variant); }
        .item-counter { background: #eee; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; color: #666; font-weight: bold; white-space: nowrap; }

        .pagination-btn {
            background: #eee;
            border: none;
            padding: 2px 8px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.7rem;
            color: var(--primary);
            transition: 0.2s;
        }
        .pagination-btn:hover:not(:disabled) { background: #ddd; }
        .pagination-btn:disabled { opacity: 0.3; cursor: not-allowed; }

        /* --- MATERIAL DESIGN MODALS --- */
        .modal { 
            display: none; 
            position: fixed; 
            inset: 0; 
            background: rgba(0,0,0,0.6); 
            z-index: 2000; 
            align-items: center; 
            justify-content: center; 
            opacity: 0; 
            transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
        }
        .modal.active { display: flex; opacity: 1; }
        
        .modal-content { 
            background: var(--surface); 
            border-radius: var(--m3-radius); 
            padding: 24px; 
            width: 90%; 
            max-width: 440px; 
            box-shadow: var(--elevation-2); 
            transform: translateY(24px); 
            transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .modal.active .modal-content { transform: translateY(0); }
        
        .modal-header { font-size: 1.4rem; margin-bottom: 16px; font-weight: 400; color: var(--on-surface); }
        .modal-body { margin-bottom: 24px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 8px; }

        /* --- REDESIGNED MEDIA VIEWER (CINEMATIC) --- */
        #mediaModal .modal-content { 
            max-width: 95vw; 
            max-height: 95vh; 
            width: 100%;
            background: #0d0c0f; /* Pitch Dark Neutral */
            color: #f4eff4; 
            padding: 0; 
            border-radius: var(--m3-radius);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255,255,255,0.05);
        }
        
        .media-header {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(to bottom, rgba(13, 12, 15, 0.95), rgba(13, 12, 15, 0));
            z-index: 101;
        }
        
        #mediaBody { 
            flex: 1;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            position: relative; 
            min-height: 400px; 
            width: 100%; 
        }
        
        .nav-btn { 
            position: absolute; 
            top: 50%; 
            transform: translateY(-50%); 
            background: rgba(255, 255, 255, 0.08); 
            backdrop-filter: blur(12px);
            color: white; 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 50%; 
            width: 56px; 
            height: 56px; 
            cursor: pointer; 
            font-size: 1.4rem; 
            transition: 0.3s cubic-bezier(0.2, 0, 0, 1); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            z-index: 100; 
            opacity: 0; 
        }
        #mediaBody:hover .nav-btn { opacity: 1; }
        .nav-btn:hover { background: rgba(255,255,255,0.2); transform: translateY(-50%) scale(1.1); }
        .nav-prev { left: 24px; } 
        .nav-next { right: 24px; }
        
        #mediaContainer { 
            width: 100%; 
            height: 100%; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            padding: 40px; 
            box-sizing: border-box; 
        }
        
        #mediaContainer img, #mediaContainer video { 
            max-width: 100%; 
            max-height: 70vh; 
            border-radius: 16px; 
            box-shadow: 0 24px 60px rgba(0,0,0,0.6); 
            transition: opacity 0.3s;
        }

        /* Vinyl Record Animation for Audio */
        .audio-player-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 32px;
            width: 100%;
            max-width: 480px;
        }
        .vinyl-disc {
            width: 240px;
            height: 240px;
            background: #111;
            border-radius: 50%;
            border: 10px solid #222;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 15px 45px rgba(0,0,0,0.5);
            animation: rotateVinyl 8s linear infinite;
            animation-play-state: paused;
        }
        .vinyl-disc.playing { animation-play-state: running; }
        .vinyl-disc::before {
            content: '';
            position: absolute;
            inset: 40px;
            border: 2px solid rgba(255,255,255,0.05);
            border-radius: 50%;
        }
        .vinyl-label {
            width: 80px;
            height: 80px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            z-index: 2;
        }
        @keyframes rotateVinyl { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* Modernized Audio Control Bar */
        #mediaContainer audio { 
            width: 100%; 
            height: 48px;
            filter: invert(100%) hue-rotate(180deg) brightness(); /* Force standard player into dark mode style */
            opacity: 0.9;
        }

        /* Standard Controls */
        .m3-input {
            width: 100%;
            padding: 14px;
            border: 1px solid var(--outline);
            border-radius: 12px;
            box-sizing: border-box;
            font-size: 1rem;
            background: transparent;
            transition: border 0.2s;
        }
        .m3-input:focus { outline: none; border: 2px solid var(--primary); padding: 13px; }

        .context-menu { position: absolute; display: none; background: #fff; border-radius: 12px; box-shadow: var(--elevation-2); z-index: 3000; min-width: 190px; padding: 6px 0; border: 1px solid var(--outline); }
        .context-menu div { padding: 10px 16px; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.85rem; }
        .context-menu div:hover { background: #f1f1f1; }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 1000; }

        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 1024px) {
            .sidebar { position: fixed; left: 0; transform: translateX(-100%); height: 100vh; box-shadow: var(--elevation-2); }
            .sidebar.active { transform: translateX(0); }
            .sidebar.active + .sidebar-overlay { display: block; }
            .menu-toggle { display: block; }
            .close-sidebar { display: block; }
            .table-header, .table-row { grid-template-columns: 44px 1fr 140px 90px 80px; }
            .col-type { display: none; }
        }

        @media (max-width: 768px) {
            .top-bar { padding: 10px 16px; flex-wrap: wrap; }
            .action-buttons { width: 100%; justify-content: space-between; order: 3; }
            .search-field { order: 2; }
            .btn { flex: 1; justify-content: center; padding: 8px 12px; font-size: 0.75rem; }
            .table-header, .table-row { grid-template-columns: 44px 1fr 120px 70px; }
            .col-date { display: none; }
        }

        @media (max-width: 480px) {
            .brand { margin-bottom: 24px; }
            .table-header, .table-row { grid-template-columns: 40px 1fr 60px; }
            .col-size, .col-date, .col-type { display: none; }
            .action-buttons .btn span { display: none; }
            .action-buttons .btn { gap: 0; padding: 10px; }
            .nav-btn { width: 44px; height: 44px; font-size: 1rem; }
            .vinyl-disc { width: 180px; height: 180px; }
        }
    </style>
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

            <div class="breadcrumb-container">
                <div class="breadcrumb-trail" id="breadcrumbTrail"></div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div id="bulkActions" style="display: none; gap: 8px;">
                        <button class="btn btn-outline" id="bulkZipBtn" onclick="submitBulkZip()" style="padding: 4px 12px; font-size: 0.75rem;background: navajowhite;">📦 Download as ZIP</button>
                        <button class="btn btn-outline" id="bulkDeleteBtn" onclick="submitBulkDelete()" style="padding: 4px 12px; font-size: 0.75rem; color: #fff;background: red;">🗑️ Delete</button>
                    </div>
                    <div id="paginationContainer" style="display:none; gap: 4px;">
                        <button class="pagination-btn" id="prevPageBtn" onclick="navigatePage(-1)">❮ Previous</button>
                        <button class="pagination-btn" id="nextPageBtn" onclick="navigatePage(1)">Next ❯</button>
                    </div>
                    <div class="item-counter" id="itemCounter">0 items</div>
                </div>
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

    <script>
    let currentDir = "<?= addslashes($relativeDir) ?>";
    let currentSearch = "<?= addslashes($searchQuery) ?>";
    let currentItems = <?= json_encode($items) ?>;
    
    // Pagination state
    let currentPage = 1;
    let totalItems = <?= $totalMatched ?>;
    let perPage = 50;

    let selectedPath = null;
    let selectedName = null;
    let currentXhr = null;
    let mediaItems = [];
    let currentMediaIndex = -1;
    let sortKey = 'name', sortOrder = 1;

    const viewableExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav'];

    window.onload = () => {
        setupDragAndDrop();
        renderExplorer();
        updateStats(<?= json_encode(['usedPercent' => number_format($usedPercent, 2), 'used' => formatSize($totalUsed), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100]) ?>);
    };

    function toggleSidebar(active) {
        const sidebar = document.getElementById('sidebar');
        if (active) sidebar.classList.add('active');
        else sidebar.classList.remove('active');
    }

    // Global Keyboard Events
    document.addEventListener('keydown', (e) => {
        if (document.getElementById('mediaModal').classList.contains('active')) {
            if (e.key === 'ArrowLeft') navigateMedia(-1);
            if (e.key === 'ArrowRight') navigateMedia(1);
            if (e.key === 'Escape') closeModal('mediaModal');
        }
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id));
            toggleSidebar(false);
        }
    });

    function renderExplorer() {
        const list = document.getElementById('fileListContent');
        const bc = document.getElementById('breadcrumbTrail');
        const counter = document.getElementById('itemCounter');
        
        // Sorting is now handled server-side for accurate pagination
        const items = currentItems;

        let bcHtml = currentSearch ? `🔍 Search: "<strong>${escapeHtml(currentSearch)}</strong>"` : `<a onclick="fetchExplorer('', '', 1)">Root</a>`;
        if (!currentSearch) {
            let cum = "";
            currentDir.split('/').filter(p => p).forEach(p => {
                cum += (cum ? '/' : '') + p;
                bcHtml += ` <span>/</span> <a onclick="fetchExplorer('${cum.replace(/'/g, "\\'")}', '', 1)">${escapeHtml(p)}</a>`;
            });
        }
        bc.innerHTML = bcHtml;

        // Render Pagination UI (Only if searching)
        const totalPages = currentSearch ? Math.ceil(totalItems / perPage) : 1;
        const pagContainer = document.getElementById('paginationContainer');
        if (currentSearch && totalPages > 1) {
            pagContainer.style.display = 'flex';
            document.getElementById('prevPageBtn').disabled = currentPage <= 1;
            document.getElementById('nextPageBtn').disabled = currentPage >= totalPages;
            counter.innerText = `Showing ${Math.min(totalItems, (currentPage - 1) * perPage + 1)}-${Math.min(totalItems, currentPage * perPage)} of ${totalItems}`;
        } else {
            pagContainer.style.display = 'none';
            counter.innerText = `${totalItems} item(s)`;
        }

        let html = "";
        if (!currentSearch && currentDir !== "" && currentPage === 1) {
            const parent = currentDir.split('/').slice(0, -1).join('/');
            html += `<div class="table-row folder" onclick="fetchExplorer('${parent.replace(/'/g, "\\'")}', '', 1)"><div class="col"></div><div class="col-name"><span class="icon">⤴️</span> ..</div></div>`;
        }

        items.forEach(f => {
            const isMedia = !f.isDir && viewableExts.includes(f.type.toLowerCase());
            const action = f.isDir ? `fetchExplorer('${f.path.replace(/'/g, "\\'")}', '', 1)` : (isMedia ? `openMediaViewer('${f.path.replace(/'/g, "\\'")}')` : '');
            
            html += `
            <div class="table-row ${f.isDir ? 'folder' : ''}" oncontextmenu="handleContextMenu(event, this)" data-path="${escapeHtml(f.path)}" data-name="${escapeHtml(f.name)}">
                <div style="text-align:center"><input type="checkbox" name="selected_items[]" value="${escapeHtml(f.path)}" onclick="updateBulkBtn()"></div>
                <div class="col-name" onclick="${action}">
                    <span class="icon">${f.isDir ? '📁' : '📄'}</span>
                    <div style="display: flex; flex-direction: column; min-width: 0;">
                        <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(f.name)}</strong>
                        ${currentSearch ? `<span style="font-size:0.6rem; color:#888;">in ${escapeHtml(f.path.substring(0, f.path.lastIndexOf('/')) || 'Root')}</span>` : ''}
                    </div>
                </div>
                <div class="col-date" style="color:#666; font-size:0.75rem;">${f.mtime_f}</div>
                <div class="col-type" style="color:#666; font-size:0.75rem;">${f.type}</div>
                <div class="col-size" style="color:#666; font-size:0.75rem;">${f.size_f}</div>
                <div style="text-align:right">
                    ${!f.isDir ? `<a href="?download=${encodeURIComponent(f.path)}" style="text-decoration:none; margin-right:8px;" title="Download" download="${escapeHtml(f.name)}">⬇️</a>` : ''}
                    <span onclick="deleteItem('${escapeHtml(f.path)}', '${escapeHtml(f.name)}')" style="cursor:pointer;" title="Delete">🗑️</span>
                </div>
            </div>`;
        });
        list.innerHTML = html || `<div style="padding:30px; text-align:center; color:#999; font-size:0.9rem;">${currentSearch ? 'No matches found.' : 'Folder empty.'}</div>`;
        document.getElementById('selectAll').checked = false;
        updateBulkBtn();
    }

    async function fetchExplorer(dir, search = "", page = 1) {
        try {
            const url = `${window.location.pathname}?dir=${encodeURIComponent(dir)}&search=${encodeURIComponent(search)}&page=${page}&sort=${sortKey}&order=${sortOrder}&ajax=1`;
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            
            currentDir = data.dir; 
            currentSearch = data.search; 
            currentItems = data.items;
            currentPage = data.page;
            totalItems = data.totalCount;
            perPage = data.perPage;

            renderExplorer();
            updateStats(data.stats);
            document.getElementById('clearSearchBtn').style.display = currentSearch ? 'inline' : 'none';
            // Sync the search input visually to match the current search state
            document.getElementById('searchInput').value = currentSearch;
        } catch (e) { uiAlert("Load failed."); }
    }

    function navigatePage(dir) {
        fetchExplorer(currentDir, currentSearch, currentPage + dir);
    }

    function updateStats(stats) {
        document.getElementById('statPercent').innerText = stats.usedPercent + '%';
        const bar = document.getElementById('statBar');
        bar.style.width = stats.usedPercent + '%';
        bar.className = 'progress-fill ' + (parseFloat(stats.usedPercent) > 90 ? 'critical' : (parseFloat(stats.usedPercent) > 75 ? 'warning' : ''));
        document.getElementById('statUsedText').innerText = `${stats.used} / 100 GB`;
        document.getElementById('statTotalFiles').innerText = `Total Files: ${stats.totalFiles}`;
        document.getElementById('uploadBtn').disabled = stats.isFull;
    }

    async function uploadItems(type) {
        const input = type === 'folder' ? document.getElementById('folderInput') : document.getElementById('uploadInput');
        if (!input.files.length) return;
        const batch = Array.from(input.files).map(f => ({ file: f, relativePath: f.webkitRelativePath || "" }));
        processUploadBatch(batch);
        input.value = '';
    }

    async function processUploadBatch(batch) {
        const card = document.getElementById('uploadStatusCard');
        const badge = document.getElementById('uploadCountBadge');
        const bar = document.getElementById('uploadProgressBar');
        const nameLabel = document.getElementById('uploadFileName');
        const speedLabel = document.getElementById('uploadSpeed');
        const sizeLabel = document.getElementById('uploadSizeBadge');

        card.style.display = 'flex';
        
        for (let i = 0; i < batch.length; i++) {
            const item = batch[i];
            const formData = new FormData();
            formData.append('upload[]', item.file);
            if (item.relativePath) formData.append('relativePath', item.relativePath);

            const startTime = Date.now();
            const xhr = new XMLHttpRequest();
            currentXhr = xhr;

            const uploadPromise = new Promise((resolve, reject) => {
                badge.innerText = `Uploading ${i + 1} of ${batch.length}`;
                nameLabel.innerText = item.file.name;
                
                xhr.upload.onprogress = e => {
                    if (e.lengthComputable) {
                        const percent = (e.loaded / e.total) * 100;
                        const elapsed = (Date.now() - startTime) / 1000;
                        const speed = elapsed > 0 ? e.loaded / elapsed : 0;
                        
                        bar.style.width = percent + '%';
                        
                        // Finalizing status update
                        if (percent >= 100) {
                            nameLabel.innerText = `${item.file.name} (Finalizing... please wait)`;
                            speedLabel.innerText = "Processing...";
                        } else {
                            nameLabel.innerText = item.file.name;
                            speedLabel.innerText = formatBytes(speed) + '/s';
                        }
                        
                        sizeLabel.innerText = `${formatBytes(e.loaded)} / ${formatBytes(e.total)}`;
                    }
                };

                xhr.onreadystatechange = () => {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) resolve();
                        else reject();
                    }
                };

                xhr.open('POST', `${window.location.pathname}?dir=${encodeURIComponent(currentDir)}&action=upload&ajax=1`, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.send(formData);
            });

            try {
                await uploadPromise;
            } catch (e) {
                if (currentXhr) {
                    uiAlert("Upload failed.");
                    break;
                }
            }
        }
        card.style.display = 'none';
        fetchExplorer(currentDir, currentSearch, currentPage);
    }

    function setupDragAndDrop() {
        const dz = document.getElementById('dropZone');
        dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('drag-over'); });
        dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
        dz.addEventListener('drop', async (e) => {
            e.preventDefault();
            dz.classList.remove('drag-over');
            const items = e.dataTransfer.items;
            if (!items) return;

            let filesToUpload = [];
            async function getFilesFromEntry(entry, path = "") {
                if (entry.isFile) {
                    return new Promise(resolve => entry.file(file => { 
                        filesToUpload.push({ file, relativePath: path + file.name }); 
                        resolve(); 
                    }));
                } else if (entry.isDirectory) {
                    const dirReader = entry.createReader();
                    const entries = await new Promise(resolve => dirReader.readEntries(resolve));
                    for (const childEntry of entries) await getFilesFromEntry(childEntry, path + entry.name + "/");
                }
            }

            for (const item of items) {
                const entry = item.webkitGetAsEntry();
                if (entry) await getFilesFromEntry(entry);
            }
            if (filesToUpload.length) processUploadBatch(filesToUpload);
        });
    }

    function openModal(id) { 
        const m = document.getElementById(id); 
        m.style.display = 'flex'; 
        setTimeout(() => {
            m.classList.add('active');
            const input = m.querySelector('input');
            if (input) input.focus();
        }, 10); 
    }

    function closeModal(id) { 
        const m = document.getElementById(id); 
        m.classList.remove('active'); 
        setTimeout(() => {
            m.style.display = 'none';
            if (id === 'mediaModal') {
                const container = document.getElementById('mediaContainer');
                const media = container.querySelector('audio, video');
                if (media) media.pause();
                container.innerHTML = '';
            }
        }, 300); 
    }

    function deleteItem(path, name) {
        uiConfirm(`Delete "${name}"?`, async () => {
            await fetch(`?delete=${encodeURIComponent(path)}&ajax=1`);
            fetchExplorer(currentDir, currentSearch, currentPage);
        });
    }

    function changeSort(key) { 
        if (sortKey === key) sortOrder *= -1; 
        else { sortKey = key; sortOrder = 1; } 
        fetchExplorer(currentDir, currentSearch, 1); // Reset to page 1 on sort change
    }
    
    function toggleSelectAll(m) { 
        document.getElementsByName('selected_items[]').forEach(cb => {
            cb.checked = m.checked;
        }); 
        updateBulkBtn(); 
    }
    
    function updateBulkBtn() {
        const checkboxes = document.querySelectorAll('input[name="selected_items[]"]');
        let checkedCount = 0;
        
        checkboxes.forEach(cb => {
            const row = cb.closest('.table-row');
            if (cb.checked) {
                checkedCount++;
                if (row) row.classList.add('selected');
            } else {
                if (row) row.classList.remove('selected');
            }
        });

        const bulkActions = document.getElementById('bulkActions');
        const deleteBtn = document.getElementById('bulkDeleteBtn');
        const zipBtn = document.getElementById('bulkZipBtn');

        if (checkedCount > 0) {
            bulkActions.style.display = 'flex';
            deleteBtn.innerHTML = `🗑️ Delete (${checkedCount})`;
            zipBtn.innerHTML = `📦 Download as ZIP (${checkedCount})`;
        } else {
            bulkActions.style.display = 'none';
        }
    }

    function handleContextMenu(e, el) { 
        e.preventDefault(); 
        selectedPath = el.dataset.path; 
        selectedName = el.dataset.name; 
        const menu = document.getElementById('contextMenu'); 
        
        let x = e.pageX;
        let y = e.pageY;
        
        // Prevent overflow
        if (x + 200 > window.innerWidth) x -= 200;
        if (y + 150 > window.innerHeight) y -= 150;

        menu.style.left = x + 'px'; 
        menu.style.top = y + 'px'; 
        menu.style.display = 'block'; 
    }
    document.addEventListener('click', () => document.getElementById('contextMenu').style.display = 'none');

    function openMediaViewer(path) {
        // We might need to load the full item info if we're on a paginated search
        const item = currentItems.find(i => i.path === path);
        if (!item) return loadMediaDirect(path);

        mediaItems = currentItems.filter(i => !i.isDir && viewableExts.includes(i.type.toLowerCase()));
        currentMediaIndex = mediaItems.findIndex(i => i.path === path);
        
        if (currentMediaIndex === -1) loadMediaDirect(path);
        else loadMedia();
        openModal('mediaModal');
    }

    function loadMedia() {
        const item = mediaItems[currentMediaIndex];
        const container = document.getElementById('mediaContainer');
        document.getElementById('mediaTitle').innerText = item.name;
        document.getElementById('mediaSubtitle').innerText = `${item.type} • ${item.size_f}`;
        const ext = item.type.toLowerCase();
        const url = `?download=${encodeURIComponent(item.path)}&t=${Date.now()}`;
        
        container.style.opacity = '0';
        setTimeout(() => {
            if (['mp4','webm','ogg'].includes(ext)) {
                container.innerHTML = `<video controls autoplay style="max-height:70vh; max-width:100%" src="${url}"></video>`;
            } else if (['mp3','wav'].includes(ext)) {
                container.innerHTML = `
                    <div class="audio-player-wrapper">
                        <div class="vinyl-disc" id="vinylDisc">
                            <div class="vinyl-label">🎵</div>
                        </div>
                        <div style="width:100%; text-align:center;">
                            <audio id="mainAudio" controls autoplay src="${url}"></audio>
                        </div>
                    </div>`;
                
                const audio = document.getElementById('mainAudio');
                const disc = document.getElementById('vinylDisc');
                audio.onplay = () => disc.classList.add('playing');
                audio.onpause = () => disc.classList.remove('playing');
                audio.onended = () => disc.classList.remove('playing');
                if (!audio.paused) disc.classList.add('playing');
            } else {
                container.innerHTML = `<img src="${url}" style="max-height:70vh; max-width:100%">`;
            }
            container.style.opacity = '1';
        }, 150);
    }

    function loadMediaDirect(path) {
        const container = document.getElementById('mediaContainer');
        const name = path.split('/').pop();
        const ext = name.split('.').pop().toLowerCase();
        document.getElementById('mediaTitle').innerText = name;
        const url = `?download=${encodeURIComponent(path)}&t=${Date.now()}`;
        if (['mp4','webm','ogg'].includes(ext)) container.innerHTML = `<video controls autoplay style="max-height:70vh; max-width:100%" src="${url}"></video>`;
        else if (['mp3','wav'].includes(ext)) container.innerHTML = `<audio controls autoplay src="${url}"></audio>`;
        else container.innerHTML = `<img src="${url}" style="max-height:70vh; max-width:100%">`;
    }

    function navigateMedia(d) { 
        if (mediaItems.length <= 1) return;
        currentMediaIndex = (currentMediaIndex + d + mediaItems.length) % mediaItems.length; 
        loadMedia(); 
    }

    function uiConfirm(msg, ok) { document.getElementById('confirmText').innerText = msg; document.getElementById('confirmOkBtn').onclick = () => { ok(); closeModal('confirmModal'); }; openModal('confirmModal'); }
    function uiAlert(msg) { document.getElementById('alertText').innerText = msg; openModal('alertModal'); }
    
    async function submitNewFolder() { 
        const n = document.getElementById('newFolderName').value.trim(); 
        if (!n) return;
        const fd = new FormData(); fd.append('newfolder', n); 
        try {
            const response = await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd}); 
            const res = await response.json();
            if (res.success) {
                closeModal('folderModal'); 
                document.getElementById('newFolderName').value = "";
                fetchExplorer(currentDir, currentSearch, currentPage); 
            } else {
                uiAlert("Folder already exists or invalid name.");
            }
        } catch(e) { uiAlert("Error creating folder."); }
    }

    function handleSearchKeyUp(e) { if(e.key === 'Enter') fetchExplorer(currentDir, e.target.value.trim(), 1); }
    function clearSearch() { document.getElementById('searchInput').value = ''; fetchExplorer(currentDir, '', 1); }
    function abortUpload() { if(currentXhr) currentXhr.abort(); location.reload(); }
    
    function submitBulkDelete() {
        const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
        uiConfirm(`Delete ${checked.length} items?`, async () => {
            const fd = new FormData(); fd.append('bulk_delete', '1'); checked.forEach(v => fd.append('selected_items[]', v));
            await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd}); 
            fetchExplorer(currentDir, currentSearch, currentPage);
        });
    }

    function submitBulkZip() {
        const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
        if (!checked.length) return;
        const form = document.createElement('form'); form.method = 'POST'; form.action = window.location.pathname;
        const zipI = document.createElement('input'); zipI.type = 'hidden'; zipI.name = 'bulk_zip'; zipI.value = '1'; form.appendChild(zipI);
        checked.forEach(v => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'selected_items[]'; i.value = v; form.appendChild(i); });
        document.body.appendChild(form); form.submit();
    }
    
    function renamePrompt() {
        document.getElementById('promptTitle').innerText = "Rename"; 
        document.getElementById('promptText').innerText = `New name for "${selectedName}":`; 
        document.getElementById('promptInput').value = selectedName;
        document.getElementById('promptOkBtn').onclick = async () => {
            const n = document.getElementById('promptInput').value.trim();
            if (!n) return;
            const fd = new FormData(); fd.append('rename_old', selectedPath); fd.append('rename_new', n);
            await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd}); 
            closeModal('promptModal'); 
            fetchExplorer(currentDir, currentSearch, currentPage);
        };
        openModal('promptModal');
    }

    function movePrompt() {
        document.getElementById('promptTitle').innerText = "Move Item"; 
        document.getElementById('promptText').innerText = "Target path (relative to root):"; 
        document.getElementById('promptInput').value = "";
        document.getElementById('promptOkBtn').onclick = async () => {
            const t = document.getElementById('promptInput').value.trim();
            if (!t) return;
            const fd = new FormData(); fd.append('move_file', selectedPath); fd.append('move_target', t);
            await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd}); 
            closeModal('promptModal'); 
            fetchExplorer(currentDir, currentSearch, currentPage);
        };
        openModal('promptModal');
    }

    function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024, dm = decimals < 0 ? 0 : decimals, sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'], i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>