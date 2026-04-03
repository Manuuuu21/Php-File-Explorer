<?php
/**
 * PHP File Explorer - Material Design 3 Redesign
 * A high-performance, feature-rich file management system with a cinematic UI.
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
$storageLimit = 100 * 1024 * 1024 * 1024; // 100 GB

// --- FORWARD DECLARATION OF HELPERS ---
function safePath($path, $realBase) {
    if (!$path || !$realBase) return false;
    $realPath = realpath($path);
    if ($realPath === false) {
        $parent = dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false) return false;
        if ($realParent !== $realBase && strpos($realParent, $realBase . DIRECTORY_SEPARATOR) !== 0) return false;
        return $path;
    }
    if ($realPath !== $realBase && strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0) return false;
    return $realPath;
}

// ================= SHARE LINK HANDLING (BEFORE AUTH) =================
$is_shared_view = false;
$is_single_file_share = false;
$single_shared_file_info = null;
$share_token = $_GET['share'] ?? null;
$allow_upload = false;

if ($share_token && preg_match('/^[a-f0-9]{32}$/', $share_token)) {
    $sharesDir = __DIR__ . '/.shares';
    $shareFile = $sharesDir . '/' . $share_token;
    if (file_exists($shareFile)) {
        $content = file_get_contents($shareFile);
        $shared_data = json_decode($content, true);
        
        if (is_array($shared_data)) {
            $shared_relative_path = $shared_data['path'];
            $allow_upload = $shared_data['allow_upload'] ?? false;
        } else {
            $shared_relative_path = trim($content);
            $allow_upload = false;
        }
        
        $shared_full_path = safePath($realBase . DIRECTORY_SEPARATOR . $shared_relative_path, $realBase);

        if ($shared_full_path && file_exists($shared_full_path)) {
            $is_shared_view = true;
            if (is_file($shared_full_path)) {
                $is_single_file_share = true;
                $allow_upload = false; // Never allow upload on single file share
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
        <title>Login - File Explorer </title>
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
            <h2>📂 Welcome </h2>
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

function getGlobalIndex($realBase, $globalIndexFile, $force = false) {
    if (!$force && file_exists($globalIndexFile)) {
        $data = json_decode(file_get_contents($globalIndexFile), true);
        if (is_array($data)) return $data;
    }
    
    $allItems = [];
    if (!is_dir($realBase)) return [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realBase, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $fileInfo) {
        $allItems[] = [
            'name' => $fileInfo->getFilename(),
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($realBase, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR)),
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

function recursiveCopy($src, $dst, $isTopLevel = true) {
    if ($isTopLevel && file_exists($dst)) {
        $pathInfo = pathinfo($dst);
        $dir = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        
        $newName = "Copy of " . $filename . $extension;
        $dst = $dir . DIRECTORY_SEPARATOR . $newName;
        
        $counter = 2;
        while (file_exists($dst)) {
            $dst = $dir . DIRECTORY_SEPARATOR . "Copy of " . $filename . " ($counter)" . $extension;
            $counter++;
        }
    }

    if (is_dir($src)) {
        if (!file_exists($dst)) @mkdir($dst, 0777, true);
        $files = array_diff(scandir($src), array('.', '..'));
        foreach ($files as $file) {
            recursiveCopy($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file, false);
        }
    } else {
        @copy($src, $dst);
    }
}

function formatSize($bytes) {
    if ($bytes >= 1099511627776) return number_format($bytes / 1099511627776, 2) . ' TB';
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

$reqDir = $_GET['dir'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_GET['ajax']);
$currentDir = safePath($baseDir . DIRECTORY_SEPARATOR . $reqDir, $realBase) ?: $realBase;
$relativeDir = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($realBase, '', $currentDir), DIRECTORY_SEPARATOR));


// Pagination & Sort Params
$page = (int)($_GET['page'] ?? 1);
$perPage = empty($searchQuery) ? 100000 : 50;
$sortKey = $_GET['sort'] ?? 'name';
$sortOrder = (int)($_GET['order'] ?? 1);

function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ================= ACTION HANDLERS (ADMIN OR AUTHORIZED SHARED VIEW) =================
if (!$is_shared_view || ($is_shared_view && $allow_upload)) {
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
        if (!empty($relativePath)) {
            $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        }
        
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $name = basename($files['name'][$i]);
                if (!empty($relativePath)) {
                    $targetFile = $currentDir . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
                    $targetDir = dirname($targetFile);
                    if (!file_exists($targetDir)) @mkdir($targetDir, 0777, true);
                } else {
                    $targetFile = $currentDir . DIRECTORY_SEPARATOR . $name;
                }
                
                if (safePath($targetFile, $realBase)) {
                    if (@move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                        $successCount++;
                    }
                }
            }
        }
        if ($successCount > 0) invalidateCache($cacheFile, $globalIndexFile);
        sendJsonResponse(['success' => true, 'count' => $successCount]);
    }
}

// ================= ACTION HANDLERS (ADMIN OR SHARED-UPLOAD) =================
if (!$is_shared_view || ($is_shared_view && $allow_upload)) {
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

    if (isset($_POST['bulk_copy'], $_POST['move_target']) && !empty($_POST['selected_items'])) {
        $targetDir = safePath($realBase . DIRECTORY_SEPARATOR . $_POST['move_target'], $realBase);
        $successCount = 0;
        $error = "";

        if ($targetDir && is_dir($targetDir)) {
            foreach ($_POST['selected_items'] as $itemPath) {
                $file = safePath($realBase . DIRECTORY_SEPARATOR . $itemPath, $realBase);
                if ($file) {
                    $newPath = $targetDir . DIRECTORY_SEPARATOR . basename($file);
                    recursiveCopy($file, $newPath, true);
                    $successCount++;
                }
            }
            if ($successCount > 0) invalidateCache($cacheFile, $globalIndexFile);
        } else {
            $error = "Invalid target directory.";
        }
        if ($isAjax) sendJsonResponse(['success' => $successCount > 0, 'copied' => $successCount, 'error' => $error]);
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
        $allow_upload_req = (isset($_POST['allow_upload']) && $_POST['allow_upload'] === '1') ? true : false;
        
        $real_path_to_share = safePath($adminRealBase . DIRECTORY_SEPARATOR . $path_to_share, $adminRealBase);
        if (!$real_path_to_share) sendJsonResponse(['success' => false, 'error' => 'Invalid path']);

        $existing_token = null;
        $files = glob($sharesDir . '/*');
        foreach($files as $file){
            if(is_file($file)) {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                if (is_array($data)) {
                    if ($data['path'] === $path_to_share && $data['allow_upload'] === $allow_upload_req) {
                        $existing_token = basename($file);
                        break;
                    }
                } else {
                    if (trim($content) === $path_to_share && !$allow_upload_req) {
                        $existing_token = basename($file);
                        break;
                    }
                }
            }
        }

        if ($existing_token) sendJsonResponse(['success' => true, 'token' => $existing_token]);

        $token = bin2hex(random_bytes(16));
        $shareData = [
            'path' => $path_to_share,
            'allow_upload' => $allow_upload_req
        ];
        file_put_contents($sharesDir . '/' . $token, json_encode($shareData));
        sendJsonResponse(['success' => true, 'token' => $token]);
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_full_index') {
        $allFiles = getGlobalIndex($adminRealBase, $globalIndexFile, true);
        sendJsonResponse($allFiles);
    }

    if (isset($_GET['storage'])) {
        $allFiles = getGlobalIndex($adminRealBase, $globalIndexFile);
        $filesOnly = array_filter($allFiles, function($f) { return !$f['isDir']; });
        
        $breakdown = [
            'video' => ['size' => 0, 'exts' => ['mp4', 'webm', 'ogg', 'mp3', 'wav']],
            'photo' => ['size' => 0, 'exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']],
            'doc' => ['size' => 0, 'exts' => ['pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']],
            'other' => ['size' => 0]
        ];

        $totalUsed = 0;
        foreach ($filesOnly as $f) {
            $totalUsed += $f['size'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $categorized = false;
            foreach ($breakdown as $key => &$data) {
                if (isset($data['exts']) && in_array($ext, $data['exts'])) {
                    $data['size'] += $f['size'];
                    $categorized = true;
                    break;
                }
            }
            if (!$categorized) $breakdown['other']['size'] += $f['size'];
        }

        // Sort by size descending
        usort($filesOnly, function($a, $b) { return $b['size'] - $a['size']; });

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        $paginatedFiles = array_slice($filesOnly, $offset, $perPage);
        
        foreach ($paginatedFiles as &$f) {
            $f['size_f'] = formatSize($f['size']);
        }

        $usedPercent = ($totalUsed / $storageLimit) * 100;

        sendJsonResponse([
            'breakdown' => $breakdown,
            'totalUsed' => $totalUsed,
            'totalUsedFormatted' => formatSize($totalUsed),
            'storageLimit' => $storageLimit,
            'storageLimitFormatted' => formatSize($storageLimit),
            'usedPercent' => number_format($usedPercent, 2),
            'files' => array_values($paginatedFiles),
            'hasMore' => ($offset + $perPage) < count($filesOnly)
        ]);
    }


    if (!empty($_POST['newfolder'])) {
        $name = preg_replace('/[^a-zA-Z0-9\s._-]/', '', $_POST['newfolder']);
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
        $disposition = (isset($_GET['force']) || ($is_shared_view && !isset($_GET['inline']))) ? 'attachment' : 'inline';
        
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
if (!$is_shared_view) {
    getGlobalIndex($adminRealBase, $globalIndexFile, !$isAjax);
}
$allItems = [];
if ($is_single_file_share) {
    $fPath = $single_shared_file_info;
    $f = basename($fPath);
    $item_path_for_js = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($realBase, '', $fPath), DIRECTORY_SEPARATOR));
    $allItems[] = [
        'name' => $f, 'path' => $item_path_for_js,
        'isDir' => false, 'mtime' => filemtime($fPath),
        'mtime_f' => date("m/d/Y, H:i", filemtime($fPath)),
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
                // Defer formatting until after pagination
                $allItems[] = $item;
            }
        }
    } else {
        $scanned = @scandir($currentDir);
        if ($scanned) {
            foreach ($scanned as $f) {
                if ($f === '.' || $f === '..') continue;
                $fPath = $currentDir . DIRECTORY_SEPARATOR . $f;
                $isDir = is_dir($fPath);
                
                $item = [
                    'name' => $f,
                    'path' => str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($realBase, '', $fPath), DIRECTORY_SEPARATOR)),
                    'isDir' => $isDir,
                    'type' => $isDir ? 'Folder' : strtoupper(pathinfo($f, PATHINFO_EXTENSION))
                ];
                
                // Only fetch stats if sorting requires them
                if ($sortKey === 'mtime' || $sortKey === 'size') {
                    $item['mtime'] = filemtime($fPath);
                    $item['size'] = $isDir ? -1 : filesize($fPath);
                } else {
                    $item['mtime'] = 0; // Placeholder
                    $item['size'] = 0;  // Placeholder
                }
                
                $allItems[] = $item;
            }
        }
    }
}

// SERVER SIDE SORTING
usort($allItems, function($a, $b) use ($sortKey, $sortOrder) {
    if ($a['isDir'] !== $b['isDir']) return $b['isDir'] ? 1 : -1;
    $valA = $a[$sortKey]; $valB = $b[$sortKey];
    if (is_string($valA)) return strnatcasecmp($valA, $valB) * $sortOrder;
    return ($valA - $valB) * $sortOrder;
});

// SERVER SIDE PAGINATION
$totalMatched = count($allItems);
$offset = ($page - 1) * $perPage;
$items = array_slice($allItems, $offset, $perPage);

// ENRICH PAGINATED ITEMS WITH STATS
foreach ($items as &$item) {
    $fPath = $realBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);
    if ($item['mtime'] === 0) $item['mtime'] = filemtime($fPath);
    if ($item['size'] === 0 && !$item['isDir']) $item['size'] = filesize($fPath);
    
    $item['mtime_f'] = date("m/d/Y, H:i", $item['mtime']);
    $item['size_f'] = $item['isDir'] ? '--' : formatSize($item['size']);
}
unset($item);

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
       $response['stats'] = ['used' => formatSize($totalUsed), 'usedPercent' => number_format($usedPercent, 2), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100, 'limit' => formatSize($storageLimit)];
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
    <script src="util-lib/pdf.min.js"></script>
    <script>
        var pdfjsLib = window['pdfjs-dist/build/pdf'];
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'util-lib/pdf.worker.min.js';
    </script>
    <script src="util-lib/ace.js"></script>
    <script src="util-lib/xlsx.full.min.js"></script>
</head>
<body>

    <?php if (!$is_shared_view): ?>
    <div class="sidebar-overlay" onclick="toggleSidebar(false)"></div>
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div style="margin-bottom:15px;" class="brand">
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
            <button id="myFilesBtn" class="btn btn-outline" style="justify-content: flex-start; border: none; background: rgb(238, 238, 238); color: var(--on-surface);">📁 My Files</button>
            <button id="storageBtn" class="btn btn-outline" style="justify-content: flex-start; border: none; background: transparent; color: var(--on-surface);">☁️ My Storage</button>
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
            
            <?php if (!$is_shared_view || $allow_upload): ?>
            <div class="action-buttons">
                <button id="uploadBtn" class="btn btn-primary" onclick="document.getElementById('uploadInput').click()"><img src="img-icon/file-icon/upload.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> <span>Upload Files</span></button>
                <input type="file" id="uploadInput" multiple style="display:none" onchange="uploadItems('file')">
                
                <button class="btn btn-outline" onclick="document.getElementById('folderInput').click()"><img src="img-icon/file-icon/folder.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> <span>Upload Folder</span></button>
                <input type="file" id="folderInput" webkitdirectory style="display:none" onchange="uploadItems('folder')">

                <?php if (!$is_shared_view || ($is_shared_view && $allow_upload)): ?>
                <button class="btn btn-outline" onclick="openModal('folderModal')"><img src="img-icon/file-icon/new-folder.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> <span>New Folder</span></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </header>

        <!-- STICKY SUB-HEADER -->
        <div class="breadcrumb-bar">
            <div class="breadcrumb-container">
                <div class="breadcrumb-trail" id="breadcrumbTrail"></div>
                <div class="breadcrumb-actions">
                    <?php if (!$is_shared_view || ($is_shared_view && $allow_upload)): ?>
                    <div id="bulkActions" class="bulk-actions-group" style="display: none;">
                        <div class="desktop-bulk-actions">
                            <?php if (!$is_shared_view): ?>
                            <button class="btn btn-tonal-primary bulkMoveBtn" id="bulkMoveBtn" onclick="movePrompt()">➡️ Move</button>
                            <button class="btn btn-tonal-primary bulkZipBtn" id="bulkZipBtn" onclick="submitBulkZip()">📦 Download as ZIP</button>
                            <?php endif; ?>
                            <button class="btn btn-tonal-danger bulkDeleteBtn" id="bulkDeleteBtn" onclick="submitBulkDelete()">🗑️ Delete</button>
                        </div>
                        <div class="mobile-bulk-actions">
                            <div class="breadcrumb-dropdown">
                                <button class="btn btn-tonal-primary" onclick="toggleBreadcrumbDropdown(this)">⚡ Actions</button>
                                <div class="breadcrumb-dropdown-content" style="right: 0; left: auto;">
                                    <?php if (!$is_shared_view): ?>
                                    <a id="m-dropdown-bulkMoveBtn" onclick="movePrompt()">➡️ Move</a>
                                    <a id="m-dropdown-bulkZipBtn" onclick="submitBulkZip()">📦 Download as ZIP</a>
                                    <?php endif; ?>
                                    <a id="m-dropdown-bulkDeleteBtn" onclick="submitBulkDelete()">🗑️ Delete</a>
                                </div>
                            </div>
                        </div>
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
            <?php if (!$is_shared_view || $allow_upload): ?><div class="drop-hint">🚀 Drop here to upload</div><?php endif; ?>
            
            <?php if (!$is_shared_view || $allow_upload): ?>
            <style>
                .btn-abort:hover { background: #fff5f5 !important; border-color: #feb2b2 !important; }
                #uploadFileList::-webkit-scrollbar { width: 6px; }
                #uploadFileList::-webkit-scrollbar-track { background: #f7fafc; }
                #uploadFileList::-webkit-scrollbar-thumb { background: #edf2f7; border-radius: 3px; }
                #uploadFileList::-webkit-scrollbar-thumb:hover { background: #e2e8f0; }
            </style>
            <div id="uploadContainer" style="display: none; width: 450px; max-height: 500px; background: #fff; position: fixed; bottom: 20px; right: 25px; box-shadow: 0px 10px 30px rgba(0,0,0,0.15); z-index: 1000; flex-direction: column; border-radius: 12px; overflow: hidden; border: 1px solid #eee;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid #f0f0f0;">
                    <div id="uploadCountBadge" style="font-weight: 600; font-size: 1rem; color: #1a1a1a;">Uploading 0 items</div>
                    <button style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #666; line-height: 1;" onclick="closeUploadContainer()">✕</button>
                </div>
                <div id="uploadFileList" style="flex-grow: 1; overflow-y: auto; padding: 0 20px;">
                    <!-- Upload items will be injected here -->
                </div>
            </div>
            <?php endif; ?>

            <div class="data-table-container">
                <div class="table-header">
                    <?php if (!$is_shared_view || ($is_shared_view && $allow_upload)): ?><div style="text-align:center"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></div><?php else: ?><div style="width:56px"></div><?php endif; ?>
                    <div style="cursor:pointer" onclick="changeSort('name')">Name</div>
                    <div class="col-date" style="cursor:pointer" onclick="changeSort('mtime')">Date Modified</div>
                    <div class="col-type" style="cursor:pointer" onclick="changeSort('type')">Type</div>
                    <div class="col-size" style="cursor:pointer" onclick="changeSort('size')">Size</div>
                    <div style="text-align:right">Actions</div>
                </div>
                <div id="fileListContent"></div>
            </div>

            <div id="storageView" style="display: none; flex-direction: column; gap: 24px; padding: 24px;">
                <h2 style="font-size: 1.5rem; font-weight: 400; margin: 0;">Storage</h2>
                
                <div style="margin-top: 8px;">
                    <span id="totalUsedSize" style="font-size: 2rem; font-weight: 500;">0 GB</span>
                    <span style="font-size: 0.9rem; color: #666;"> of <span id="storageLimitText">0 GB</span> used</span>
                </div>

                <div class="storage-chart-container" style="padding: 0; background: transparent;">
                    <div class="stack-chart" style="height: 8px; border-radius: 4px; margin-bottom: 12px; background: #f0f0f0; display: flex; overflow: hidden;">
                        <div id="segmentVideo" class="chart-segment segment-video" style="width: 0%; background: #4285f4; height: 100%;"></div>
                        <div id="segmentPhoto" class="chart-segment segment-photo" style="width: 0%; background: #fbbc05; height: 100%;"></div>
                        <div id="segmentDoc" class="chart-segment segment-doc" style="width: 0%; background: #ea4335; height: 100%;"></div>
                        <div id="segmentOther" class="chart-segment segment-other" style="width: 0%; background: #34a853; height: 100%;"></div>
                        <div id="segmentFree" class="chart-segment segment-free" style="width: 0%; background: transparent; height: 100%;"></div>
                    </div>
                    <div class="chart-legend" style="display: flex; gap: 16px; font-size: 0.8rem; color: #555; flex-wrap: wrap;">
                        <div class="legend-item" style="display: flex; align-items: center; gap: 8px;"><div class="legend-color" style="background: #4285f4; border-radius: 50%; width: 8px; height: 8px;"></div> Video/Audio <span id="legendVideoSize" style="color: #888; margin-left: -4px;"></span></div>
                        <div class="legend-item" style="display: flex; align-items: center; gap: 8px;"><div class="legend-color" style="background: #fbbc05; border-radius: 50%; width: 8px; height: 8px;"></div> Photos <span id="legendPhotoSize" style="color: #888; margin-left: -4px;"></span></div>
                        <div class="legend-item" style="display: flex; align-items: center; gap: 8px;"><div class="legend-color" style="background: #ea4335; border-radius: 50%; width: 8px; height: 8px;"></div> Documents <span id="legendDocSize" style="color: #888; margin-left: -4px;"></span></div>
                        <div class="legend-item" style="display: flex; align-items: center; gap: 8px;"><div class="legend-color" style="background: #34a853; border-radius: 50%; width: 8px; height: 8px;"></div> Other <span id="legendOtherSize" style="color: #888; margin-left: -4px;"></span></div>
                        <div class="legend-item" style="display: flex; align-items: center; gap: 8px;"><div class="legend-color" style="background: #ccc; border-radius: 50%; width: 8px; height: 8px;"></div> Available Space <span id="legendFreeSize" style="color: #888; margin-left: -4px;"></span></div>
                    </div>
                </div>

                <div style="margin-top: 24px;">
                    <div style="display: grid; grid-template-columns: 1fr 120px; padding: 12px 16px; border-bottom: 1px solid #eee; font-weight: 500; font-size: 0.9rem; color: #333;">
                        <div>Name</div>
                        <div style="text-align: right;">Size</div>
                    </div>
                    <div id="storageFileList" class="storage-file-list" style="display: flex; flex-direction: column;"></div>
                    <div class="load-more-container" style="display: flex; justify-content: center; padding: 16px 0;">
                        <button id="loadMoreStorage" class="btn btn-tonal" style="display: none;">Load More</button>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- PDF Slideshow Container -->
    <div id="pdfSlideshowContainer" class="slideshow-overlay" style="display: none;">
        <div class="slideshow-controls">
            <button class="slideshow-btn" onclick="prevPdfPage()">❮ Prev</button>
            <span id="slideshowPageNum" style="color: white; font-weight: 500;">Page 1 / 1</span>
            <button class="slideshow-btn" onclick="nextPdfPage()">Next ❯</button>
            <button class="slideshow-btn" onclick="exitPdfSlideshow()" style="background: rgba(255, 67, 53, 0.2); color: #ff8a80;">Exit</button>
        </div>
        <div id="slideshowCanvasContainer" class="slideshow-canvas-container"></div>
    </div>
    
    <div id="snackbarContainer" class="snackbar-container"></div>
    
    <!-- MODALS -->
    <div id="mediaModal" class="modal"><div class="modal-content"><div class="media-header"><div style="display: flex; flex-direction: column; overflow: hidden; gap: 4px;"><span id="mediaTitle">Viewer</span><span id="mediaSubtitle">Preview</span></div><div style="display: flex; align-items: center; gap: 16px;"><button id="pdfSlideshowBtn" class="btn btn-tonal-primary" style="display: none; padding: 8px 16px; border-radius: 20px; font-size: 0.85rem;" onclick="startPdfSlideshow()">📽️ Slideshow</button><span onclick="closeModal('mediaModal')" style="cursor:pointer; font-size:32px; line-height: 1;">&times;</span></div></div><div id="mediaBody"><button class="nav-btn nav-prev" onclick="navigateMedia(-1)">❮</button><div id="mediaContainer"></div><button class="nav-btn nav-next" onclick="navigateMedia(1)">❯</button></div></div></div>
    
    <?php if (!$is_shared_view || ($is_shared_view && $allow_upload)): ?>
    <div id="folderModal" class="modal"><div class="modal-content"><div class="modal-header">New Folder</div><div class="modal-body"><input type="text" id="newFolderName" class="m3-input" placeholder="Folder Name" autofocus></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('folderModal')">Cancel</button><button class="btn btn-primary" onclick="submitNewFolder()">Create</button></div></div></div>
    <?php endif; ?>
    <div id="confirmModal" class="modal"><div class="modal-content"><div class="modal-header" id="confirmTitle">Confirm</div><div class="modal-body" id="confirmText"></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('confirmModal')">Cancel</button><button class="btn btn-primary" id="confirmOkBtn" style="background: var(--danger)">Delete</button></div></div></div>
    <div id="promptModal" class="modal"><div class="modal-content"><div class="modal-header" id="promptTitle">Input</div><div class="modal-body"><div id="promptText" style="margin-bottom:12px;"></div><input type="text" id="promptInput" class="m3-input"></div><div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('promptModal')">Cancel</button><button class="btn btn-primary" id="promptOkBtn">Confirm</button></div></div></div>
    <div id="alertModal" class="modal"><div class="modal-content"><div class="modal-header" id="alertTitle">Notice</div><div class="modal-body" id="alertText"></div><div class="modal-footer"><button class="btn btn-primary" onclick="closeModal('alertModal')">OK</button></div></div></div>
    
    <div id="moveModal" class="modal">
        <div class="modal-content" style="max-width: 600px; padding: 0; overflow: hidden;">
            <div class="modal-header" id="moveModalTitle" style="padding: 24px 24px 16px 24px; margin-bottom: 0;">Move Item(s)</div>
            <div class="modal-body" style="margin-bottom: 0;">
                <div class="move-modal-table">
                    <div class="move-modal-header">
                        <div id="moveModalBreadcrumb"></div>
                    </div>
                    <div class="move-modal-header">
                        <div style="padding-left: 42px;">Name</div>
                    </div>
                    <div id="moveModalList" class="move-modal-list">
                        <!-- Folders will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 24px 24px 24px; background: #fff;">
                <button class="btn btn-move-cancel" onclick="closeModal('moveModal')">Cancel</button>
                <button class="btn btn-move-confirm" id="moveModalConfirmBtn">Move</button>
            </div>
        </div>
    </div>
    
    <?php if (!$is_shared_view): ?>
    <div id="shareModal" class="modal">
        <div class="modal-content share-modal-content">
            <div class="share-modal-body">
                <h2 class="share-title">Share Link</h2>
                <p class="share-subtitle">Anyone with this link can view and download.</p>
                
                <div class="share-input-group">
                    <input type="text" id="shareLinkInput" class="share-input" readonly>
                    <button class="btn-copy" id="copyShareBtn" onclick="copyShareLink()">Copy</button>
                </div>
                
                <div id="shareUploadOption" class="advanced-permissions-box" style="display:none;">
                    <div class="advanced-header">
                        <span class="advanced-title">🛡️ Advance Permissions</span>
                        <select id="shareAllowUpload" class="advanced-select" onchange="updateShareLink()">
                            <option value="0">NO</option>
                            <option value="1">YES</option>
                        </select>
                    </div>
                    <div id="sharePermissionText" class="permissions-list">
                        <div class="permission-item"><img src="img-icon/file-icon/upload.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Allow viewers to upload files and folders</div>
                        <div class="permission-item"><img src="img-icon/file-icon/new-folder.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Allow viewers to create new folders</div>
                        <div class="permission-item"><img src="img-icon/file-icon/delete.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Allow viewers to delete items</div>
                    </div>
                </div>
                
                <div class="share-modal-footer">
                    <button class="btn-close" onclick="closeModal('shareModal')">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu">
        <div onclick="sharePrompt()"><img src="img-icon/file-icon/share-link.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Share link</div>
        <div onclick="renamePrompt()"><img src="img-icon/file-icon/rename.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Rename</div>
        <div id="contextMenubulkMoveBtn" onclick="movePrompt()"><img src="img-icon/file-icon/move-file.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Move</div>
        <div id="contextMenubulkZipBtn" onclick="submitBulkZip()"><img src="img-icon/file-icon/zip.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Download as ZIP</div>
        <div id="contextMenubulkDeleteBtn" onclick="submitBulkDelete()"><img src="img-icon/file-icon/delete.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Delete</div>
    </div>
    <?php endif; ?>

    <script src="index.js?v=<?= @filemtime('index.js') ?: time() ?>"></script>
    <script>
    // Initialize the JS with data from PHP
    isSharedView = <?= $is_shared_view ? 'true' : 'false' ?>;
    allowUpload = <?= $allow_upload ? 'true' : 'false' ?>;
    isSingleFileShare = <?= $is_single_file_share ? 'true' : 'false' ?>;
    sharedFolderName = "<?= $is_shared_view ? basename($realBase) : '' ?>";
    currentDir = "<?= addslashes($relativeDir) ?>";
    currentSearch = "<?= addslashes($searchQuery) ?>";
    currentItems = <?= json_encode($items) ?>;
    totalItems = <?= $totalMatched ?>;
    currentPage = <?= $page ?>;
    sortKey = '<?= addslashes($sortKey) ?>';
    sortOrder = <?= $sortOrder ?>;
    window.fileIndex = [];
    
    window.onload = () => {
        fetchFullIndex();
        if (!isSharedView || (isSharedView && allowUpload)) setupDragAndDrop();
        
        const params = new URLSearchParams(window.location.search);
        if (params.get('mystorage') === 'quota' && !isSharedView) {
            showStorageView();
            const storageBtn = document.getElementById('storageBtn');
            const myFilesBtn = document.getElementById('myFilesBtn');
            if (storageBtn) storageBtn.style.background = "#eee";
            if (myFilesBtn) myFilesBtn.style.background = "";
        } else {
            renderExplorer();
            const myFilesBtn = document.getElementById('myFilesBtn');
            if (myFilesBtn && !isSharedView) myFilesBtn.style.background = "#eee";
        }

        <?php if (!$is_shared_view): ?>
        updateStats(<?= json_encode(['usedPercent' => number_format($usedPercent, 2), 'used' => formatSize($totalUsed), 'totalFiles' => $totalFilesStorage, 'isFull' => $usedPercent >= 100]) ?>);
        <?php endif; ?>
    };
    </script>
</body>
</html>
<?php ob_end_flush(); ?>