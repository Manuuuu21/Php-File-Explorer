// --- STATE VARIABLES ---
let fullIndex = null;
let currentDir = "";
let currentSearch = "";
let currentItems = [];
let currentPage = 1;
let totalItems = 0;
let perPage = 50;
let sortKey = 'name';
let sortOrder = 1;
let isSharedView = false;
let allowUpload = false;
let isSingleFileShare = false;
let sharedFolderName = "";
let clipboardItems = [];
let clipboardSourceDir = "";
let clipboardAction = "cut"; // "cut" or "copy"
let activePaths = [];
let lastClickTime = 0;
let lastClickPath = null;

// --- INTERNAL STATE ---
let selectedPath = null;
let selectedName = null;
let currentXhr = null;
let mediaItems = [];
let currentMediaIndex = -1;
let currentPdfDoc = null;
let currentPdfPage = 1;
let isSlideshowActive = false;
let slideshowTimer = null;

let storageLimit = `100 GB`; // This is only for txt display. Make sure it is sync in the php code storagelimit.

const viewableExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'txt', 'html', 'css', 'php', 'js', 'xlsx', 'xls'];

const FILE_TYPE_ICONS = {
    'zip': 'img-icon/file-icon/zip.png',
    'rar': 'img-icon/file-icon/rar.png',
    'doc': 'img-icon/file-icon/doc.png',
    'docx': 'img-icon/file-icon/doc.png',
    'pdf': 'img-icon/file-icon/pdf.png',
    'ppt': 'img-icon/file-icon/ppt.png',
    'pptx': 'img-icon/file-icon/ppt.png',
    'xls': 'img-icon/file-icon/xls.png',
    'xlsx': 'img-icon/file-icon/xls.png'
};

// --- HELPERS ---
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
function escapeJs(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}
function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024, dm = decimals < 0 ? 0 : decimals, sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'], i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

// FIXED DOWNLOAD FUNCTION
function downloadFile(path) {
    const link = document.createElement('a');
    const params = new URLSearchParams(window.location.search);
    const shareToken = params.get('share');
    
    let url = "";
    if (isSharedView && shareToken) {
        url = `?share=${shareToken}&download=${encodeURIComponent(path)}`;
    } else {
        url = `?download=${encodeURIComponent(path)}&force=1`;
    }
    
    link.href = url;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// --- URL NAVIGATION SUPPORT ---
window.onpopstate = function(event) {
    const params = new URLSearchParams(window.location.search);
    
    if (params.get('mystorage') === 'quota' && !isSharedView) {
        showStorageView();
        const storageBtn = document.getElementById('storageBtn');
        const myFilesBtn = document.getElementById('myFilesBtn');
        if (storageBtn) storageBtn.style.background = "#eee";
        if (myFilesBtn) myFilesBtn.style.background = "";
        return;
    } else {
        hideStorageView();
        const storageBtn = document.getElementById('storageBtn');
        const myFilesBtn = document.getElementById('myFilesBtn');
        if (storageBtn) storageBtn.style.background = "";
        if (myFilesBtn) myFilesBtn.style.background = "#eee";
    }

    const dir = params.get('dir') || '';
    const search = params.get('search') || '';
    const page = parseInt(params.get('page') || '1');
    fetchExplorer(dir, search, page, false);
};

function toggleSidebar(active) {
    const sidebar = document.getElementById('sidebar');
    if (active) sidebar.classList.add('active');
    else sidebar.classList.remove('active');
}

let storagePage = 1;
let storageLoading = false;
let storageHasMore = true;

function showStorageView() {
    currentDir = ""; // Reset current directory to root when entering storage view
    const explorerBody = document.querySelector('.explorer-body > .data-table-container');
    const storageView = document.getElementById('storageView');
    const breadcrumbBar = document.querySelector('.breadcrumb-bar');
    const paginationContainer = document.getElementById('paginationContainer');
    const itemCounter = document.getElementById('itemCounter');

    if (explorerBody) explorerBody.style.display = 'none';
    if (breadcrumbBar) breadcrumbBar.style.display = 'none';
    if (paginationContainer) paginationContainer.style.display = 'none';
    if (itemCounter) itemCounter.style.display = 'none';
    if (storageView) storageView.style.display = 'flex';

    storagePage = 1;
    storageHasMore = true;
    const list = document.getElementById('storageFileList');
    if (list) list.innerHTML = '';
    fetchStorageData();
    toggleSidebar(false);
}

function hideStorageView(skipRender = false) {
    const explorerBody = document.querySelector('.explorer-body > .data-table-container');
    const storageView = document.getElementById('storageView');
    const breadcrumbBar = document.querySelector('.breadcrumb-bar');
    const itemCounter = document.getElementById('itemCounter');

    if (storageView) storageView.style.display = 'none';
    if (explorerBody) explorerBody.style.display = 'block';
    if (breadcrumbBar) breadcrumbBar.style.display = 'block';
    if (itemCounter) itemCounter.style.display = 'block';
    
    if (!skipRender) renderExplorer();
}

async function fetchStorageData() {
    if (storageLoading || !storageHasMore) return;
    storageLoading = true;

    try {
        const response = await fetch(`?storage=1&page=${storagePage}&ajax=1`);
        const data = await response.json();

        if (storagePage === 1) {
            renderStorageSummary(data);
            renderStorageChart(data);
        }

        renderStorageFileList(data.files);
        storageHasMore = data.hasMore;
        storagePage++;

        const loadMoreBtn = document.getElementById('loadMoreStorage');
        if (loadMoreBtn) {
            loadMoreBtn.style.display = 'none'; // Hidden, using infinite scroll
        }
    } catch (e) {
        console.error('Failed to fetch storage data:', e);
        uiAlert("Failed to load storage data.");
    } finally {
        storageLoading = false;
    }
}

function renderStorageSummary(data) {
    const used = document.getElementById('totalUsedSize');
    const limit = document.getElementById('storageLimitText');
    const percent = document.getElementById('usedPercentText');
    if (used) used.innerText = data.totalUsedFormatted;
    if (limit) limit.innerText = `${storageLimit}`;
    if (percent) percent.innerText = data.usedPercent + '%';
}

function renderStorageChart(data) {
    const segments = data.breakdown;
    const total = data.storageLimit;

    const setWidth = (id, size) => {
        const el = document.getElementById(id);
        if (el) el.style.width = (size / total * 100) + '%';
    };

    setWidth('segmentVideo', segments.video.size);
    setWidth('segmentPhoto', segments.photo.size);
    setWidth('segmentDoc', segments.doc.size);
    setWidth('segmentOther', segments.other.size);
    
    const freeSize = Math.max(0, total - data.totalUsed);
    const segmentFree = document.getElementById('segmentFree');
    if (segmentFree) segmentFree.style.width = (freeSize / total * 100) + '%';

    const setLegendSize = (id, size) => {
        const el = document.getElementById(id);
        if (el) el.innerText = `(${formatBytes(size)})`;
    };

    setLegendSize('legendVideoSize', segments.video.size);
    setLegendSize('legendPhotoSize', segments.photo.size);
    setLegendSize('legendDocSize', segments.doc.size);
    setLegendSize('legendOtherSize', segments.other.size);
    setLegendSize('legendFreeSize', freeSize);
}

function renderStorageFileList(files) {
    const list = document.getElementById('storageFileList');
    if (!list) return;
    files.forEach(file => {
        const item = document.createElement('div');
        item.className = 'storage-file-item';
        item.style.display = 'grid';
        item.style.gridTemplateColumns = '1fr 120px';
        item.style.padding = '12px 16px';
        item.style.borderBottom = '1px solid #eee';
        item.style.alignItems = 'center';
        item.innerHTML = `
            <div class="storage-file-name" title="${escapeHtml(file.path)}" style="display: flex; align-items: center; gap: 12px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <span style="color: #666; font-size: 1.2rem;">${getFileIcon(file)}</span>
                ${escapeHtml(file.name)}
            </div>
            <div class="storage-file-size" style="text-align: right; color: #666; font-size: 0.9rem;">${file.size_f}</div>
        `;
        list.appendChild(item);
    });
}


// --- GLOBAL KEYBOARD EVENTS ---
document.addEventListener('keydown', (e) => {
    if (document.getElementById('mediaModal')?.classList.contains('active')) {
        if (isSlideshowActive) return; // Don't navigate files if slideshow is active
        if (e.key === 'ArrowLeft') navigateMedia(-1);
        if (e.key === 'ArrowRight') navigateMedia(1);
        if (e.key === 'Escape') closeModal('mediaModal');
    }
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id));
        if (!isSharedView) toggleSidebar(false);
    }
    
    // CTRL+A (Select All)
    if (e.ctrlKey && e.key.toLowerCase() === 'a') {
        if (isSharedView || document.querySelector('.modal.active')) return;
        if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA') return;
        e.preventDefault();
        const selectAllCb = document.getElementById('selectAll');
        if (selectAllCb) selectAllCb.checked = true;
        const checkboxes = document.querySelectorAll('input[name="selected_items[]"]');
        checkboxes.forEach(cb => cb.checked = true);
        updateBulkBtn();
    }
    
    // CTRL+C (Copy)
    if (e.ctrlKey && e.key.toLowerCase() === 'c') {
        if (isSharedView || document.querySelector('.modal.active')) return;
        const selected = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
        if (selected.length > 0) {
            clipboardItems = selected;
            clipboardSourceDir = currentDir;
            clipboardAction = "copy";
            renderExplorer();
            showSnackbar(`${selected.length} items copied to clipboard`, { actionText: '', actionUrl: '#' });
        } else if (activePaths.length > 0) {
            clipboardItems = [...activePaths];
            clipboardSourceDir = currentDir;
            clipboardAction = "copy";
            renderExplorer();
            showSnackbar(`${activePaths.length} items copied to clipboard`, { actionText: '', actionUrl: '#' });
        }
    }

    // CTRL+X (Cut)
    if (e.ctrlKey && e.key.toLowerCase() === 'x') {
        if (isSharedView || document.querySelector('.modal.active')) return;
        const selected = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
        if (selected.length > 0) {
            clipboardItems = selected;
            clipboardSourceDir = currentDir;
            clipboardAction = "cut";
            renderExplorer(); // Update opacity
            showSnackbar(`${selected.length} items cut to clipboard`, { actionText: '', actionUrl: '#' });
        } else if (activePaths.length > 0) {
            clipboardItems = [...activePaths];
            clipboardSourceDir = currentDir;
            clipboardAction = "cut";
            renderExplorer();
            showSnackbar(`${activePaths.length} items cut to clipboard`, { actionText: '', actionUrl: '#' });
        }
    }

    // CTRL+V (Paste)
    if (e.ctrlKey && e.key.toLowerCase() === 'v') {
        if (isSharedView || clipboardItems.length === 0 || document.querySelector('.modal.active')) return;
        pasteItems();
    }
});

function toggleBreadcrumbDropdown(btn) {
    const content = btn.nextElementSibling;
    content.classList.toggle('show');
}

document.addEventListener('click', function(event) {
    const dropdowns = document.querySelectorAll('.breadcrumb-dropdown-content');
    const target = event.target;
    dropdowns.forEach(dropdown => {
        if (!dropdown.previousElementSibling.contains(target) && !dropdown.contains(target)) {
            dropdown.classList.remove('show');
        }
    });
});

function getFileIcon(item) {
    if (item.isDir) return `<img src="img-icon/file-icon/folder.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />`;
    const name = item.name.toLowerCase();
    const ext = name.split('.').pop();
    
    if (FILE_TYPE_ICONS[ext]) {
        return `<img src="${FILE_TYPE_ICONS[ext]}" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />`;
    }

    if (name.endsWith('.jpg') || name.endsWith('.jpeg') || name.endsWith('.png') || name.endsWith('.gif') || name.endsWith('.bmp')) {
        return `<img src="img-icon/file-icon/photo.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />`;
    }
    if (name.endsWith('.mp4') || name.endsWith('.avi') || name.endsWith('.mov') || name.endsWith('.mkv')) {
        return `<img src="img-icon/file-icon/video.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />`;
    }
    if (name.endsWith('.mp3') || name.endsWith('.wav') || name.endsWith('.ogg')) {
        return `<img src="img-icon/file-icon/audio.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />`;
    }
    return `<img src="img-icon/file-icon/file.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />`;
}

function renderExplorer(loading = false) {
    const list = document.getElementById('fileListContent');
    const bc = document.getElementById('breadcrumbTrail');
    const counter = document.getElementById('itemCounter');
    const items = currentItems;

    let bcHtml = '';
    const isMobile = window.innerWidth <= 768;
    
    if (isSingleFileShare) {
        bcHtml = '';
    } else if (currentSearch) {
        bcHtml = `🔍 Search: "<strong>${escapeHtml(currentSearch)}</strong>"`;
    } else {
        const pathParts = currentDir.split('/').filter(p => p);
        let rootName = isSharedView ? (sharedFolderName || 'Shared Files') : 'Root';
        let cumulativePath = "";

        if (isMobile && pathParts.length > 0) {
            bc.classList.add('mobile-bc');
            bcHtml = `<div class="breadcrumb-dropdown">
                <a class="bc-link" onclick="fetchExplorer('', '', 1)">${escapeHtml(rootName)}</a>
                <span class="bc-sep">/</span>
                <span class="breadcrumb-more-btn" onclick="toggleBreadcrumbDropdown(this)">...</span>
                <div class="breadcrumb-dropdown-content">`;
            
            pathParts.forEach((p, idx) => {
                cumulativePath += (cumulativePath ? '/' : '') + p;
                bcHtml += `<a onclick="fetchExplorer('${escapeJs(cumulativePath)}', '', 1)">${escapeHtml(p)}</a>`;
            });
            
            bcHtml += `</div></div>`;
        } else {
            bc.classList.remove('mobile-bc');
            bcHtml = `<a class="bc-link" onclick="fetchExplorer('', '', 1)">${escapeHtml(rootName)}</a>`;
            
            if (pathParts.length > 3) {
                bcHtml += ` <span class="bc-sep">/</span> <div class="breadcrumb-dropdown"><span class="breadcrumb-more-btn" onclick="toggleBreadcrumbDropdown(this)">...</span><div class="breadcrumb-dropdown-content">`;
                for (let i = 0; i < pathParts.length - 2; i++) {
                    cumulativePath += (cumulativePath ? '/' : '') + pathParts[i];
                    bcHtml += `<a onclick="fetchExplorer('${escapeJs(cumulativePath)}', '', 1)">${escapeHtml(pathParts[i])}</a>`;
                }
                bcHtml += `</div></div>`;
                for (let i = pathParts.length - 2; i < pathParts.length; i++) {
                    cumulativePath += (cumulativePath ? '/' : '') + pathParts[i];
                    bcHtml += ` <span class="bc-sep">/</span> <a class="bc-link" onclick="fetchExplorer('${escapeJs(cumulativePath)}', '', 1)">${escapeHtml(pathParts[i])}</a>`;
                }
            } else {
                pathParts.forEach(p => {
                    cumulativePath += (cumulativePath ? '/' : '') + p;
                    bcHtml += ` <span class="bc-sep">/</span> <a class="bc-link" onclick="fetchExplorer('${escapeJs(cumulativePath)}', '', 1)">${escapeHtml(p)}</a>`;
                });
            }
        }
    }
    bc.innerHTML = bcHtml;

    const totalPages = Math.ceil(totalItems / perPage);
    const pagContainer = document.getElementById('paginationContainer');
    if (currentSearch && totalPages > 1) {
        pagContainer.style.display = 'flex';
        document.getElementById('prevPageBtn').disabled = currentPage <= 1;
        document.getElementById('nextPageBtn').disabled = currentPage >= totalPages;
        document.getElementById('pageIndicator').innerText = currentPage;
        counter.innerText = `Showing ${Math.min(totalItems, (currentPage - 1) * perPage + 1)}-${Math.min(totalItems, currentPage * perPage)} of ${totalItems}`;
    } else {
        pagContainer.style.display = 'none';
        counter.innerText = `${totalItems} item(s)`;
    }

    let html = "";
    if (loading) {
        list.innerHTML = `<div style="padding:30px; text-align:center; color:#999; font-size:0.9rem;">loading... Please wait</div>`;
        return;
    }

    if (!currentSearch && currentDir !== "" && currentPage === 1) {
        const parent = currentDir.split('/').slice(0, -1).join('/');
        html += `<div class="table-row folder" style="position:sticky;top:40px;background:white;z-index:1;" onclick="fetchExplorer('${escapeJs(parent)}', '', 1)"><div class="col"></div><div class="col-name"> <span class="icon"><img src="img-icon/file-icon/back.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" /> </span> ..</div></div>`;
    }

    items.forEach(f => {
        let actionParams = '';
        if (f.isDir) {
            actionParams = `fetchExplorer('${escapeJs(f.path)}', '', 1)`;
        } else {
            const isMedia = viewableExts.includes(f.type.toLowerCase());
            if (isMedia) {
                actionParams = `openMediaViewer('${escapeJs(f.path)}')`;
            }
        }

        const isCut = clipboardAction === 'cut' && clipboardItems.includes(f.path);
        const isActive = activePaths.includes(f.path);
        const isUploading = f.isUploading || f.isCreating || f.isMoving || f.isCopying || f.isDeleting;
        
        let downloadBtn = (!f.isDir && !isUploading) ? `<span class="action-icon download-btn" onclick="event.stopPropagation(); downloadFile('${escapeJs(f.path)}')" title="Download"> <img src="img-icon/file-icon/download.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" /> </span>` : '';
        let contextMenu = (isSharedView || isUploading) ? '' : `oncontextmenu="handleContextMenu(event, this)"`;
        let checkbox = (isSharedView && !allowUpload) ? `<div style="width:56px"></div>` : `<div style="text-align:center"><input type="checkbox" name="selected_items[]" value="${escapeHtml(f.path)}" ${isActive ? 'checked' : ''} ${isUploading ? 'disabled' : ''} onclick="event.stopPropagation(); updateBulkBtn()"></div>`;
        let actions = (isSharedView && !allowUpload) || isUploading ? downloadBtn : `${downloadBtn} <span class="action-icon delete-btn" onclick="event.stopPropagation(); deleteItem('${escapeJs(f.path)}', '${escapeJs(f.name)}')" title="Delete"> <img src="img-icon/file-icon/delete.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" /> </span>`;

        let rowStyle = isUploading ? 'opacity: 0.6; pointer-events: none;' : '';
        let nameContent = f.isUploading ? `<strong>${escapeHtml(f.name)}</strong> <span data-upload-progress="${escapeHtml(f.name)}" style="font-size:0.7rem; color:var(--primary);">Uploading... ${f.progress || 0}%</span>` : 
                          f.isCreating ? `<strong>${escapeHtml(f.name)}</strong> <span style="font-size:0.7rem; color:var(--primary);">Creating folder...</span>` :
                          f.isMoving ? `<strong>${escapeHtml(f.name)}</strong> <span style="font-size:0.7rem; color:var(--primary);">Moving...</span>` :
                          f.isCopying ? `<strong>${escapeHtml(f.name)}</strong> <span style="font-size:0.7rem; color:var(--primary);">Copying...</span>` :
                          f.isDeleting ? `<strong>${escapeHtml(f.name)}</strong> <span style="font-size:0.7rem; color:var(--primary);">Deleting...</span>` :
                          `<strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(f.name)}</strong>`;

        html += `
        <div class="table-row ${f.isDir ? 'folder' : ''} ${isCut ? 'cut-item' : ''} ${isActive ? 'active-row' : ''}" 
             ${contextMenu} 
             style="${rowStyle}"
             data-path="${escapeHtml(f.path)}" 
             data-name="${escapeHtml(f.name)}"
             onclick="${isUploading ? '' : `handleRowClick(event, '${escapeJs(f.path)}', ${f.isDir ? `'dir'` : `'file'`})`}">
            ${checkbox}
            <div class="col-name">
                <span class="icon">${isUploading ? '<img src="img-icon/file-icon/hour-glass.png" style="width:24px; height:24px; vertical-align:middle;" referrerPolicy="no-referrer" />' : getFileIcon(f)}</span>
                <div style="display: flex; flex-direction: column; min-width: 0;">
                    ${nameContent}
                    ${currentSearch ? `<span style="font-size:0.6rem; color:#888;">in ${escapeHtml(f.path.substring(0, f.path.lastIndexOf('/')) || 'Root')}</span>` : ''}
                </div>
            </div>
            <div class="col-date" style="color:#666; font-size:0.75rem;">${f.mtime_f}</div>
            <div class="col-type" style="color:#666; font-size:0.75rem;">${f.type}</div>
            <div class="col-size" style="color:#666; font-size:0.75rem;">${f.size_f}</div>
            <div style="text-align:right; display:flex; justify-content: flex-end; gap: 8px;">
                ${actions}
            </div>
        </div>`;
    });
    list.innerHTML = html || `<div style="padding:30px; text-align:center; color:#999; font-size:0.9rem;">${currentSearch ? 'No matches found.' : 'Folder empty.'}</div>`;
    if (!isSharedView || allowUpload) {
        document.getElementById('selectAll').checked = false;
        updateBulkBtn();
    }
}

window.addEventListener('resize', () => {
    if (currentItems.length > 0) renderExplorer();
});

function handleRowClick(e, path, itemType) {
    if (e.target.tagName === 'INPUT' || e.target.classList.contains('action-icon')) return;
    
    const now = Date.now();
    const isDoubleClick = (path === lastClickPath && (now - lastClickTime) < 300);

    if (e.ctrlKey) {
        if (activePaths.includes(path)) {
            activePaths = activePaths.filter(p => p !== path);
        } else {
            activePaths.push(path);
        }
        renderExplorer();
    } else {
        if (isDoubleClick) {
            lastClickTime = 0;
            lastClickPath = null;
            
            if (itemType === 'dir') {
                fetchExplorer(path, '', 1);
            } else {
                const ext = path.split('.').pop().toLowerCase();
                if (viewableExts.includes(ext)) {
                    openMediaViewer(path);
                }
            }
        } else {
            lastClickTime = now;
            lastClickPath = path;
            activePaths = [path];
            renderExplorer();
        }
    }
}

function showSnackbar(message, options = {}) {
    const container = document.getElementById('snackbarContainer');
    if (!container) return;

    const duration = options.duration || 3000;
    const snackbar = document.createElement('div');
    snackbar.className = 'snackbar';
    
    let actionsHtml = '';
    if (options.actionText) {
        actionsHtml = `<button class="snackbar-btn">${escapeHtml(options.actionText)}</button>`;
    }
    
    snackbar.innerHTML = `
        <div class="snackbar-content">${escapeHtml(message)}</div>
        <div class="snackbar-actions">
            ${actionsHtml}
            <button class="snackbar-close">&times;</button>
        </div>
    `;
    
    container.appendChild(snackbar);

    const close = () => {
        if (snackbar.classList.contains('out')) return;
        snackbar.classList.add('out');
        setTimeout(() => {
            if (snackbar.parentNode === container) {
                container.removeChild(snackbar);
            }
        }, 300);
    };

    snackbar.querySelector('.snackbar-close').onclick = close;

    if (options.actionText && options.actionCallback) {
        snackbar.querySelector('.snackbar-btn').onclick = () => {
            options.actionCallback();
            close();
        };
    }

    if (!options.persistent) {
        setTimeout(close, duration);
    }
    
    return { close };
}

async function pasteItems() {
    const transferSnackbar = showSnackbar('Transferring...', { persistent: true });
    
    const fd = new FormData();
    if (clipboardAction === 'cut') {
        fd.append('bulk_move', '1');
    } else {
        fd.append('bulk_copy', '1');
    }
    fd.append('move_target', currentDir);
    clipboardItems.forEach(itemPath => fd.append('selected_items[]', itemPath));
    
    const sourceFolder = clipboardSourceDir.split('/').filter(p => p).pop() || 'Root';
    const destFolder = currentDir.split('/').filter(p => p).pop() || 'Root';
    const count = clipboardItems.length;

    try {
        clipboardItems.forEach(path => {
            const name = path.split('/').pop();
            const itemInView = currentItems.find(i => i.path === path);
            
            if (clipboardAction === 'cut' && itemInView) {
                // Moving from current folder: mark the original as moving
                itemInView.isMoving = true;
            } else {
                // Copying (from anywhere) or Moving from another folder: create a new optimistic row
                const optimisticName = clipboardAction === 'copy' ? `${name} (copy)` : name;
                const optimisticItem = {
                    name: optimisticName,
                    path: 'optimistic_' + Math.random(), // Temporary unique path to avoid collisions
                    isDir: itemInView ? itemInView.isDir : (path.endsWith('/') || name.indexOf('.') === -1), // Heuristic for dir
                    size_f: '-',
                    type: itemInView ? itemInView.type : 'item',
                    mtime_f: 'Just now',
                    isMoving: clipboardAction === 'cut',
                    isCopying: clipboardAction === 'copy'
                };
                currentItems.unshift(optimisticItem);
            }
        });
        renderExplorer();
        const result = await (await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, { method:'POST', body:fd })).json();
        
        transferSnackbar.close();

        if(result.error) {
            uiAlert(`Paste failed: ${result.error}`);
        } else {
            const actionText = clipboardAction === 'cut' ? 'moved' : 'copied';
            if (clipboardAction === 'cut') {
                clipboardItems = [];
                clipboardSourceDir = "";
            }
            await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
            showSnackbar(`${count} files have been ${actionText} from ${sourceFolder} to "${destFolder}"`, { actionText: '' });
        }
    } catch(e) { 
        transferSnackbar.close();
        uiAlert("An error occurred during the paste operation."); 
        await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
    }
}

async function fetchExplorer(dir, search = "", page = 1, updateHistory = true, forceServer = false) {
    if (fullIndex && !isSharedView && !forceServer) {
        currentDir = dir;
        currentSearch = search;
        
        let filtered = fullIndex.filter(item => {
            if (search) {
                return item.name.toLowerCase().includes(search.toLowerCase());
            }
            if (!dir) {
                return item.path.indexOf('/') === -1;
            }
            const prefix = dir + '/';
            if (item.path.startsWith(prefix)) {
                const relative = item.path.substring(prefix.length);
                return relative.indexOf('/') === -1;
            }
            return false;
        });

        currentItems = filtered.map(item => ({
            ...item,
            mtime_f: new Date(item.mtime * 1000).toISOString().replace('T', ' ').substring(0, 16),
            size_f: item.isDir ? '--' : formatBytes(item.size)
        }));

        sortItemsLocally();
        
        totalItems = currentItems.length;
        perPage = search ? 50 : 100000;
        currentPage = page;

        if (search) {
            const start = (page - 1) * perPage;
            currentItems = currentItems.slice(start, start + perPage);
        }

        activePaths = [];
        lastClickTime = 0;
        lastClickPath = null;

        const myFilesBtn = document.getElementById('myFilesBtn');
        if (myFilesBtn) myFilesBtn.style.background = "#eee";

        const clearSearchBtn = document.getElementById('clearSearchBtn');
        const searchInput = document.getElementById('searchInput');
        if (clearSearchBtn) clearSearchBtn.style.display = currentSearch ? 'inline' : 'none';
        if (searchInput) searchInput.value = currentSearch;

        renderExplorer();

        if (updateHistory) {
            const searchParams = new URLSearchParams(window.location.search);
            searchParams.delete('mystorage');
            if (dir) searchParams.set('dir', dir); else searchParams.delete('dir');
            if (search) searchParams.set('search', search); else searchParams.delete('search');
            if (page > 1) searchParams.set('page', page); else searchParams.delete('page');
            
            let newUrl = window.location.pathname;
            const qs = searchParams.toString();
            if (qs) newUrl += '?' + qs;
            
            window.history.pushState({ dir, search, page }, '', newUrl);
        }
        return;
    }

    try {
        let url = `?dir=${encodeURIComponent(dir)}&search=${encodeURIComponent(search)}&page=${page}&sort=${sortKey}&order=${sortOrder}&ajax=1`;
        if (isSharedView) {
            const shareToken = new URLSearchParams(window.location.search).get('share');
            url = `?share=${shareToken}&dir=${encodeURIComponent(dir)}&page=${page}&sort=${sortKey}&order=${sortOrder}&ajax=1`;
        }
        
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await response.json();
        
        if (!isSharedView) await fetchFullIndex();

        activePaths = [];
        lastClickTime = 0;
        lastClickPath = null;
        currentDir = data.dir; 
        currentSearch = data.search; 
        currentItems = data.items;
        sortItemsLocally();
        currentPage = data.page;
        totalItems = data.totalCount;
        perPage = data.perPage;

        const myFilesBtn = document.getElementById('myFilesBtn');
        if (myFilesBtn) myFilesBtn.style.background = "#eee";

        renderExplorer();
        if (!isSharedView) {
            updateStats(data.stats);
            const clearSearchBtn = document.getElementById('clearSearchBtn');
            const searchInput = document.getElementById('searchInput');
            if (clearSearchBtn) clearSearchBtn.style.display = currentSearch ? 'inline' : 'none';
            if (searchInput) searchInput.value = currentSearch;
        }

        if (updateHistory) {
            const searchParams = new URLSearchParams(window.location.search);
            searchParams.delete('mystorage');
            if (dir) searchParams.set('dir', dir); else searchParams.delete('dir');
            if (search) searchParams.set('search', search); else searchParams.delete('search');
            if (page > 1) searchParams.set('page', page); else searchParams.delete('page');
            
            let newUrl = window.location.pathname;
            const qs = searchParams.toString();
            if (qs) newUrl += '?' + qs;
            
            window.history.pushState({ dir, search, page }, '', newUrl);
        }
    } catch (e) { console.error(e); if (!isSharedView) uiAlert("Load failed."); }
}

function navigatePage(dir) {
    fetchExplorer(currentDir, currentSearch, currentPage + dir);
    const myFilesBtn = document.getElementById('myFilesBtn');
    myFilesBtn.style.background = "";
}

function updateStats(stats) {
    document.getElementById('statPercent').innerText = stats.usedPercent + '%';
    const bar = document.getElementById('statBar');
    bar.style.width = stats.usedPercent + '%';
    bar.className = 'progress-fill ' + (parseFloat(stats.usedPercent) > 90 ? 'critical' : (parseFloat(stats.usedPercent) > 75 ? 'warning' : ''));
    document.getElementById('statUsedText').innerText = `${stats.used} / ${storageLimit}`;
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

    const storageView = document.getElementById('storageView');
    const isStorageView = storageView && storageView.style.display === 'flex';
    
    // If in storage view, switch to Root immediately for better responsiveness
    if (isStorageView) {
        const storageBtn = document.getElementById('storageBtn');
        const myFilesBtn = document.getElementById('myFilesBtn');
        if (storageBtn) storageBtn.style.background = "";
        if (myFilesBtn) myFilesBtn.style.background = "#eee";
        hideStorageView(true);
        currentDir = "";
        currentItems = [];
        renderExplorer(true); // Show loading while we prepare the optimistic list
    }

    // Optimistic: Add uploading items to the explorer
    const addedTopLevels = new Set();
    batch.forEach(item => {
        let targetName = item.file.name;
        let isDir = false;

        if (item.relativePath && item.relativePath.includes('/')) {
            targetName = item.relativePath.split('/')[0];
            isDir = true;
        }

        if (addedTopLevels.has(targetName)) return;
        addedTopLevels.add(targetName);

        // Check if already exists to avoid duplicates
        if (!currentItems.find(i => i.name === targetName)) {
            currentItems.unshift({
                name: targetName,
                path: (isStorageView ? "" : currentDir) + ((isStorageView ? "" : currentDir) ? '/' : '') + targetName,
                isDir: isDir,
                size_f: isDir ? '-' : formatBytes(item.file.size),
                type: isDir ? 'folder' : (item.file.name.split('.').pop() || 'file'),
                mtime_f: 'Just now',
                isUploading: true,
                progress: 0
            });
            totalItems++;
        }
    });
    renderExplorer();

    card.style.display = 'flex';
    
    let failed = false;
    for (let i = 0; i < batch.length; i++) {
        const item = batch[i];
        const fileName = item.relativePath || item.file.name;
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
                    const percent = Math.round((e.loaded / e.total) * 100);
                    const elapsed = (Date.now() - startTime) / 1000;
                    const speed = elapsed > 0 ? e.loaded / elapsed : 0;
                    bar.style.width = percent + '%';
                    
                    // Update individual item progress in UI (Direct DOM update for speed)
                    let targetProgressName = item.file.name;
                    if (item.relativePath && item.relativePath.includes('/')) {
                        targetProgressName = item.relativePath.split('/')[0];
                    }

                    const uploadingItem = currentItems.find(it => it.name === targetProgressName && it.isUploading);
                    if (uploadingItem) {
                        uploadingItem.progress = percent;
                        const progressSpan = document.querySelector(`[data-upload-progress="${escapeHtml(targetProgressName)}"]`);
                        if (progressSpan) {
                            if (item.relativePath && item.relativePath.includes('/')) {
                                progressSpan.innerText = `Uploading...`;
                            } else {
                                progressSpan.innerText = `Uploading... ${percent}%`;
                            }
                        }
                    }

                    if (percent >= 100) {
                        nameLabel.innerText = `${item.file.name} (Finalizing...)`;
                        speedLabel.innerText = "Processing...";
                    } else {
                        nameLabel.innerText = item.file.name;
                        speedLabel.innerText = formatBytes(speed) + '/s';
                    }
                    sizeLabel.innerText = `${formatBytes(e.loaded)} / ${formatBytes(e.total)}`;
                }
            };
            xhr.onreadystatechange = () => { if (xhr.readyState === 4) { if (xhr.status === 200) resolve(); else reject(); } };
            const uploadDir = isStorageView ? "" : currentDir;
            let uploadUrl = `${window.location.pathname}?dir=${encodeURIComponent(uploadDir)}&action=upload&ajax=1`;
            if (isSharedView) {
                const shareToken = new URLSearchParams(window.location.search).get('share');
                uploadUrl = `${window.location.pathname}?share=${shareToken}&dir=${encodeURIComponent(uploadDir)}&action=upload&ajax=1`;
            }
            xhr.open('POST', uploadUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        });
        try { 
            await uploadPromise; 
            // Individual item upload finished, but we keep isUploading=true until the whole batch is done
        } catch (e) { 
            console.error('Upload failed for:', fileName, e);
            failed = true;
            break; // Stop the batch on any failure
        }
    }
    card.style.display = 'none';
    currentXhr = null;

    // Cleanup uploading status
    currentItems.forEach(item => {
        if (item.isUploading) {
            item.isUploading = false;
            item.progress = 100;
        }
    });
    sortItemsLocally();
    renderExplorer();

    if (!failed) {
        showSnackbar(`${batch.length} file(s) uploaded successfully.`);
    }

    if (isStorageView) {
        fetchExplorer('', '', 1, true, true);
    } else {
        fetchExplorer(currentDir, currentSearch, currentPage, true, true);
    }
}

function setupDragAndDrop() {
    // Storage Button
    const storageBtn = document.getElementById('storageBtn');
    if (storageBtn) {
        storageBtn.addEventListener('click', (e) => {
            e.preventDefault();
            showStorageView();
            storageBtn.style.background = "#eee";
            const myFilesBtn = document.getElementById('myFilesBtn');
            if (myFilesBtn) myFilesBtn.style.background = "";
            
            const url = new URL(window.location.pathname, window.location.origin);
            url.searchParams.set('mystorage', 'quota');
            window.history.pushState({}, '', url);
        });
    }

    // My Files Button
    const myFilesBtn = document.getElementById('myFilesBtn');
    if (myFilesBtn) {
        myFilesBtn.addEventListener('click', (e) => {
            myFilesBtn.style.background = "#eee";
            if (storageBtn) storageBtn.style.background = "";
            e.preventDefault();
            hideStorageView();
            toggleSidebar(false);
            
            const url = new URL(window.location.pathname, window.location.origin);
            window.history.pushState({}, '', url);
            
            fetchExplorer('', '', 1);
        });
    }

    // Load More Storage (fallback)
    const loadMoreStorage = document.getElementById('loadMoreStorage');
    if (loadMoreStorage) {
        loadMoreStorage.addEventListener('click', fetchStorageData);
    }

    // Infinite scroll for storage view
    const explorerBody = document.querySelector('.explorer-body');
    if (explorerBody) {
        explorerBody.addEventListener('scroll', () => {
            const storageView = document.getElementById('storageView');
            if (storageView && storageView.style.display !== 'none') {
                if (explorerBody.scrollTop + explorerBody.clientHeight >= explorerBody.scrollHeight - 100) {
                    fetchStorageData();
                }
            }
        });
    }

    // Handle back from storage (e.g. clicking Root or other folders)
    const originalFetchExplorer = fetchExplorer;
    fetchExplorer = function(...args) {
        hideStorageView();
        return originalFetchExplorer.apply(this, args);
    };

    const dz = document.getElementById('dropZone');
    dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', async (e) => {
        e.preventDefault();
        dz.classList.remove('drag-over');
        const items = e.dataTransfer.items; if (!items) return;
        const initialEntries = [];
        for (let i = 0; i < items.length; i++) { const entry = items[i].webkitGetAsEntry(); if (entry) initialEntries.push(entry); }
        let filesToUpload = [];
        async function getFilesFromEntry(entry, path = "") {
            if (entry.isFile) return new Promise(resolve => entry.file(file => { filesToUpload.push({ file, relativePath: path + file.name }); resolve(); }));
            else if (entry.isDirectory) {
                const dirReader = entry.createReader(); let allEntries = [];
                const readMore = async () => new Promise(resolve => dirReader.readEntries(async results => { if (results.length > 0) { allEntries = allEntries.concat(results); await readMore(); resolve(); } else resolve(); }));
                await readMore();
                for (const childEntry of allEntries) await getFilesFromEntry(childEntry, path + entry.name + "/");
            }
        }
        for (const entry of initialEntries) await getFilesFromEntry(entry);
        if (filesToUpload.length) processUploadBatch(filesToUpload);
    });
}

function openModal(id) { 
    const m = document.getElementById(id); if (!m) return;
    m.style.display = 'flex'; 
    setTimeout(() => { m.classList.add('active'); const input = m.querySelector('input'); if (input) input.focus(); }, 10); 
}

function closeModal(id) { 
    const m = document.getElementById(id); if (!m) return;
    m.classList.remove('active'); 
    setTimeout(() => {
        m.style.display = 'none';
        if (id === 'mediaModal') {
            const container = document.getElementById('mediaContainer');
            const media = container.querySelector('audio, video');
            if (media) media.pause();
            container.innerHTML = '';
            currentPdfDoc = null;
        }
    }, 300); 
}

function deleteItem(path, name) { 
    uiConfirm(`Delete "${name}"?`, async () => { 
        // Optimistic UI: Mark as deleting
        const item = currentItems.find(i => i.path === path);
        if (item) {
            item.isDeleting = true;
            renderExplorer();
        }
        
        try {
            let url = `?delete=${encodeURIComponent(path)}&ajax=1`;
            if (isSharedView) {
                const shareToken = new URLSearchParams(window.location.search).get('share');
                url = `?share=${shareToken}&delete=${encodeURIComponent(path)}&ajax=1`;
            }
            const res = await fetch(url);
            if (!res.ok) throw new Error("Delete failed");
            await fetchExplorer(currentDir, currentSearch, currentPage, false, true);
            showSnackbar(`"${name}" deleted successfully.`);
        } catch (e) {
            uiAlert(`Failed to delete "${name}".`);
            await fetchExplorer(currentDir, currentSearch, currentPage, true, true); // Restore list
        }
    }); 
}
function sortItemsLocally() {
    if (!currentItems || currentItems.length === 0) return;
    
    currentItems.sort((a, b) => {
        // Special handling for directories to always be on top if sorting by name
        if (sortKey === 'name') {
            if (a.isDir && !b.isDir) return -1 * sortOrder;
            if (!a.isDir && b.isDir) return 1 * sortOrder;
        }

        let valA = a[sortKey];
        let valB = b[sortKey];

        // Use localeCompare for proper string sorting (Numbers, Aa-Zz)
        if (typeof valA === 'string' && typeof valB === 'string') {
            return valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' }) * sortOrder;
        }

        if (valA < valB) return -1 * sortOrder;
        if (valA > valB) return 1 * sortOrder;
        return 0;
    });
}

function changeSort(key) { if (sortKey === key) sortOrder *= -1; else { sortKey = key; sortOrder = 1; } fetchExplorer(currentDir, currentSearch, 1); }
function toggleSelectAll(m) { document.getElementsByName('selected_items[]').forEach(cb => { cb.checked = m.checked; }); updateBulkBtn(); }

function updateBulkBtn() {
    const checkboxes = document.querySelectorAll('input[name="selected_items[]"]'); 
    let checkedCount = 0;
    activePaths = [];
    checkboxes.forEach(cb => {
        const row = cb.closest('.table-row');
        if (cb.checked) { 
            checkedCount++; 
            activePaths.push(cb.value);
            if (row) row.classList.add('active-row'); 
        } else { 
            if (row) row.classList.remove('active-row'); 
        }
    });
    const bulkActions = document.getElementById('bulkActions');
    if (!bulkActions) return;

    if (checkedCount > 0) {
        bulkActions.style.display = 'flex';
        
        const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
        if (bulkDeleteBtn) bulkDeleteBtn.innerHTML = `<img src="img-icon/file-icon/delete.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Delete (${checkedCount})`;
        
        const bulkMoveBtn = document.getElementById('bulkMoveBtn');
        if (bulkMoveBtn) bulkMoveBtn.innerHTML = `<img src="img-icon/file-icon/move-file.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Move (${checkedCount})`;
        
        const bulkZipBtn = document.getElementById('bulkZipBtn');
        if (bulkZipBtn) bulkZipBtn.innerHTML = `<img src="img-icon/file-icon/zip.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Download as ZIP (${checkedCount})`;

        const mBulkDeleteBtn = document.getElementById('m-dropdown-bulkDeleteBtn');
        if (mBulkDeleteBtn) mBulkDeleteBtn.innerHTML = `<img src="img-icon/file-icon/delete.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Delete (${checkedCount})`;
        
        const mBulkMoveBtn = document.getElementById('m-dropdown-bulkMoveBtn');
        if (mBulkMoveBtn) mBulkMoveBtn.innerHTML = `<img src="img-icon/file-icon/move-file.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Move (${checkedCount})`;
        
        const mBulkZipBtn = document.getElementById('m-dropdown-bulkZipBtn');
        if (mBulkZipBtn) mBulkZipBtn.innerHTML = `<img src="img-icon/file-icon/zip.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Download as ZIP (${checkedCount})`;

        const ctxBulkMoveBtn = document.getElementById('contextMenubulkMoveBtn');
        if (ctxBulkMoveBtn) ctxBulkMoveBtn.innerHTML = `<img src="img-icon/file-icon/move-file.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Move (${checkedCount})`;
        
        const ctxBulkDeleteBtn = document.getElementById('contextMenubulkDeleteBtn');
        if (ctxBulkDeleteBtn) ctxBulkDeleteBtn.innerHTML = `<img src="img-icon/file-icon/delete.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Delete (${checkedCount})`;
        
        const ctxBulkZipBtn = document.getElementById('contextMenubulkZipBtn');
        if (ctxBulkZipBtn) ctxBulkZipBtn.innerHTML = `<img src="img-icon/file-icon/zip.png" style="width:18px; height:18px; vertical-align:middle;" referrerPolicy="no-referrer" /> Download as ZIP (${checkedCount})`;
    } else {
        bulkActions.style.display = 'none';
    }
}

function handleContextMenu(e, el) { 
    e.preventDefault(); 
    
    const path = el.dataset.path;
    
    // If the clicked item is NOT in the current selection, reset selection
    if (!activePaths.includes(path)) {
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;

        document.querySelectorAll('.table-row').forEach(row => {
            row.classList.remove('active-row');
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.checked = false;
            }
        });
        
        el.classList.add('active-row');
        const checkbox = el.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.checked = true;
        }
        activePaths = [path];
        updateBulkBtn();
    }
    
    selectedPath = el.dataset.path; selectedName = el.dataset.name; 
    const menu = document.getElementById('contextMenu'); 
    let x = e.pageX, y = e.pageY;
    if (x + 200 > window.innerWidth) x -= 200;
    if (y + 150 > window.innerHeight) y -= 150;
    menu.style.left = x + 'px'; menu.style.top = y + 'px'; menu.style.display = 'block'; 
}
document.addEventListener('click', () => { 
    const menu = document.getElementById('contextMenu');
    if (menu && menu.style.display === 'block') {
        menu.style.display = 'none';
        document.querySelectorAll('.table-row').forEach(row => {
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (!checkbox || !checkbox.checked) {
                row.classList.remove('active-row');
            }
        });
    }
});

function openMediaViewer(path) {
    const item = currentItems.find(i => i.path === path);
    if (!item) return;
    mediaItems = currentItems.filter(i => !i.isDir && viewableExts.includes(i.type.toLowerCase()));
    currentMediaIndex = mediaItems.findIndex(i => i.path === path);
    if (currentMediaIndex === -1) return;
    loadMedia();
    openModal('mediaModal');
}

const codeExts = ['txt', 'html', 'css', 'php', 'js'];

function loadMedia() {
    const item = mediaItems[currentMediaIndex];
    const container = document.getElementById('mediaContainer');
    document.getElementById('mediaTitle').innerText = item.name;
    document.getElementById('mediaSubtitle').innerText = `${item.type} • ${item.size_f}`;
    const ext = item.type.toLowerCase();
    let url = `?download=${encodeURIComponent(item.path)}&t=${Date.now()}`;
    if (isSharedView) {
        const shareToken = new URLSearchParams(window.location.search).get('share');
        url = `?share=${shareToken}&download=${encodeURIComponent(item.path)}&inline=1&t=${Date.now()}`;
    }
    
    const slideshowBtn = document.getElementById('pdfSlideshowBtn');
    if (slideshowBtn) slideshowBtn.style.display = (ext === 'pdf') ? 'inline-block' : 'none';

    container.style.opacity = '0';
    setTimeout(async () => {
        if (['mp4','webm','ogg'].includes(ext)) {
            container.innerHTML = `<video controls autoplay style="max-height:70vh; max-width:100%" src="${url}"></video>`;
        } else if (['mp3','wav'].includes(ext)) {
            container.innerHTML = `<div class="audio-player-wrapper"><div class="vinyl-disc" id="vinylDisc"><div class="vinyl-label">🎵</div></div><div style="width:125%; text-align:center;"><audio id="mainAudio" controls autoplay src="${url}"></audio></div></div>`;
            const audio = document.getElementById('mainAudio'), disc = document.getElementById('vinylDisc');
            audio.onplay = () => disc.classList.add('playing');
            audio.onpause = audio.onended = () => disc.classList.remove('playing');
            if (!audio.paused) disc.classList.add('playing');
        } else if (ext === 'pdf') {
            container.innerHTML = `<div class="pdf-viewer-container" id="pdfViewer">
                <div style="color: white; padding: 20px;">Loading PDF...</div>
            </div>`;
            renderPdf(url);
        } else if (codeExts.includes(ext)) {
            container.innerHTML = `<div id="codeEditor" style="width:100%; height:70vh; border-radius:12px;"></div>`;
            try {
                const response = await fetch(url);
                const content = await response.text();
                const editor = ace.edit("codeEditor");
                editor.setTheme("ace/theme/monokai");
                
                let mode = "ace/mode/text";
                if (ext === 'html') mode = "ace/mode/html";
                else if (ext === 'css') mode = "ace/mode/css";
                else if (ext === 'js') mode = "ace/mode/javascript";
                else if (ext === 'php') mode = "ace/mode/php";
                
                editor.session.setMode(mode);
                editor.setValue(content, -1);
                editor.setReadOnly(true);
                editor.setShowPrintMargin(false);
                editor.setOptions({
                    fontSize: "14px",
                    fontFamily: "'JetBrains Mono', 'Fira Code', monospace",
                });
            } catch (e) {
                container.innerHTML = `<div style="color:white; padding:20px;">Error loading file content.</div>`;
            }
        } else if (['xlsx', 'xls'].includes(ext)) {
            container.innerHTML = `<div class="excel-viewer-container" id="excelViewer">
                <div style="color: white; padding: 20px;">Loading Spreadsheet...</div>
            </div>`;
            renderExcel(url);
        } else {
            container.innerHTML = `<img src="${url}" style="max-height:70vh; max-width:100%">`;
        }
        container.style.opacity = '1';
    }, 150);
}

async function renderExcel(url) {
    const container = document.getElementById('excelViewer');
    try {
        const response = await fetch(url);
        const arrayBuffer = await response.arrayBuffer();
        const data = new Uint8Array(arrayBuffer);
        const workbook = XLSX.read(data, { type: 'array' });
        
        container.innerHTML = ''; // Clear loading message
        
        // Create tab navigation for sheets
        const tabsContainer = document.createElement('div');
        tabsContainer.className = 'excel-tabs';
        
        const gridContainer = document.createElement('div');
        gridContainer.className = 'excel-grid-viewport';
        
        workbook.SheetNames.forEach((sheetName, index) => {
            const tab = document.createElement('div');
            tab.className = `excel-tab ${index === 0 ? 'active' : ''}`;
            tab.innerText = sheetName;
            tab.onclick = () => {
                document.querySelectorAll('.excel-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                displaySheet(workbook.Sheets[sheetName], gridContainer);
            };
            tabsContainer.appendChild(tab);
        });
        
        container.appendChild(gridContainer);
        container.appendChild(tabsContainer);
        
        // Display first sheet by default
        displaySheet(workbook.Sheets[workbook.SheetNames[0]], gridContainer);
        
    } catch (error) {
        console.error('Error rendering Excel:', error);
        container.innerHTML = `<div style="color: #ff8a80; padding: 20px;">Error loading Spreadsheet: ${error.message}</div>`;
    }
}

function displaySheet(sheet, container) {
    const html = XLSX.utils.sheet_to_html(sheet, { editable: false });
    container.innerHTML = html;
    
    // Post-process the table to add Excel-like styling
    const table = container.querySelector('table');
    if (table) {
        table.className = 'excel-table';
        
        // Add row numbers and column headers if they don't exist
        const rows = table.querySelectorAll('tr');
        
        // Add column headers (A, B, C...)
        if (rows.length > 0) {
            const firstRow = rows[0];
            const colCount = firstRow.querySelectorAll('td').length;
            const headerRow = document.createElement('tr');
            headerRow.className = 'excel-header-row';
            
            // Corner cell
            const corner = document.createElement('th');
            corner.className = 'excel-corner';
            headerRow.appendChild(corner);
            
            for (let i = 0; i < colCount; i++) {
                const th = document.createElement('th');
                th.className = 'excel-col-header';
                th.innerText = getColumnLabel(i);
                
                const handle = document.createElement('div');
                handle.className = 'excel-col-resize-handle';
                handle.onmousedown = (e) => initResize(e, 'col', th, i, table);
                th.appendChild(handle);
                
                headerRow.appendChild(th);
            }
            table.querySelector('tbody').insertBefore(headerRow, firstRow);
        }
        
        // Add row numbers (1, 2, 3...)
        rows.forEach((row, index) => {
            const th = document.createElement('th');
            th.className = 'excel-row-header';
            th.innerText = index + 1;
            
            const handle = document.createElement('div');
            handle.className = 'excel-row-resize-handle';
            handle.onmousedown = (e) => initResize(e, 'row', th, index, table);
            th.appendChild(handle);
            
            row.insertBefore(th, row.firstChild);
        });
    }
}

function initResize(e, type, header, index, table) {
    e.preventDefault();
    e.stopPropagation();

    const startPos = type === 'col' ? e.pageX : e.pageY;
    const startSize = type === 'col' ? header.offsetWidth : header.offsetHeight;

    const onMouseMove = (moveEvent) => {
        const delta = (type === 'col' ? moveEvent.pageX : moveEvent.pageY) - startPos;
        const newSize = Math.max(20, startSize + delta);
        
        if (type === 'col') {
            // Set width for all cells in this column
            const colIndex = index + 1; // +1 because of row header
            const rows = table.querySelectorAll('tr');
            rows.forEach(row => {
                const cell = row.children[colIndex];
                if (cell) {
                    cell.style.width = newSize + 'px';
                    cell.style.minWidth = newSize + 'px';
                    cell.style.maxWidth = newSize + 'px';
                }
            });
            // Also set width for the header itself
            header.style.width = newSize + 'px';
            header.style.minWidth = newSize + 'px';
            header.style.maxWidth = newSize + 'px';
        } else {
            // Set height for the entire row
            const row = header.parentElement;
            row.style.height = newSize + 'px';
            // Ensure all cells in the row respect the height
            Array.from(row.children).forEach(cell => {
                cell.style.height = newSize + 'px';
            });
        }
    };

    const onMouseUp = () => {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        document.body.style.cursor = '';
    };

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
    document.body.style.cursor = type === 'col' ? 'col-resize' : 'row-resize';
}

function getColumnLabel(index) {
    let label = '';
    while (index >= 0) {
        label = String.fromCharCode((index % 26) + 65) + label;
        index = Math.floor(index / 26) - 1;
    }
    return label;
}

async function renderPdf(url) {
    const container = document.getElementById('pdfViewer');
    try {
        const loadingTask = pdfjsLib.getDocument(url);
        currentPdfDoc = await loadingTask.promise;
        container.innerHTML = ''; // Clear loading message
        
        for (let pageNum = 1; pageNum <= currentPdfDoc.numPages; pageNum++) {
            const page = await currentPdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: 1.5 });
            
            const canvas = document.createElement('canvas');
            canvas.className = 'pdf-page-canvas';
            const context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            
            const renderContext = {
                canvasContext: context,
                viewport: viewport
            };
            
            container.appendChild(canvas);
            await page.render(renderContext).promise;
        }
    } catch (error) {
        console.error('Error rendering PDF:', error);
        container.innerHTML = `<div style="color: #ff8a80; padding: 20px;">Error loading PDF: ${error.message}</div>`;
    }
}

async function startPdfSlideshow() {
    if (!currentPdfDoc) return;
    isSlideshowActive = true;
    currentPdfPage = 1;
    
    const container = document.getElementById('pdfSlideshowContainer');
    container.style.display = 'flex';
    
    // Request Fullscreen
    if (container.requestFullscreen) {
        container.requestFullscreen();
    } else if (container.webkitRequestFullscreen) {
        container.webkitRequestFullscreen();
    } else if (container.msRequestFullscreen) {
        container.msRequestFullscreen();
    }
    
    renderSlideshowPage();
    
    // Trigger controls to show initially
    const controls = document.querySelector('.slideshow-controls');
    if (controls) {
        controls.classList.add('show-controls');
        if (slideshowTimer) clearTimeout(slideshowTimer);
        slideshowTimer = setTimeout(() => {
            if (isSlideshowActive) controls.classList.remove('show-controls');
        }, 3000);
    }
}

async function renderSlideshowPage() {
    if (!currentPdfDoc || !isSlideshowActive) return;
    
    const canvasContainer = document.getElementById('slideshowCanvasContainer');
    const pageIndicator = document.getElementById('slideshowPageNum');
    
    canvasContainer.innerHTML = '<div style="color: white;">Loading page...</div>';
    pageIndicator.innerText = `Page ${currentPdfPage} / ${currentPdfDoc.numPages}`;
    
    try {
        const page = await currentPdfDoc.getPage(currentPdfPage);
        
        // Calculate scale to exactly fit screen while maintaining aspect ratio
        const unscaledViewport = page.getViewport({ scale: 1.0 });
        const scale = Math.min(window.innerWidth / unscaledViewport.width, window.innerHeight / unscaledViewport.height);
        const viewport = page.getViewport({ scale: scale });
        
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        
        // Use exactly the calculated viewport size which matches the screen's inner dimensions at one edge
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        
        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };
        
        canvasContainer.innerHTML = '';
        canvasContainer.appendChild(canvas);
        await page.render(renderContext).promise;
    } catch (e) {
        console.error(e);
        canvasContainer.innerHTML = '<div style="color: #ff8a80;">Error loading page</div>';
    }
}

function nextPdfPage() {
    if (!currentPdfDoc || currentPdfPage >= currentPdfDoc.numPages) return;
    currentPdfPage++;
    renderSlideshowPage();
}

function prevPdfPage() {
    if (currentPdfPage <= 1) return;
    currentPdfPage--;
    renderSlideshowPage();
}

function exitPdfSlideshow() {
    isSlideshowActive = false;
    document.getElementById('pdfSlideshowContainer').style.display = 'none';
    document.body.style.cursor = 'default';
    if (slideshowTimer) clearTimeout(slideshowTimer);
    
    if (document.exitFullscreen) {
        if (document.fullscreenElement) document.exitFullscreen();
    } else if (document.webkitExitFullscreen) {
        if (document.webkitFullscreenElement) document.webkitExitFullscreen();
    }
}

// Handle Fullscreen Change (e.g. Esc key)
document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement && isSlideshowActive) {
        exitPdfSlideshow();
    }
});
document.addEventListener('webkitfullscreenchange', () => {
    if (!document.webkitFullscreenElement && isSlideshowActive) {
        exitPdfSlideshow();
    }
});

// Keyboard navigation for slideshow
document.addEventListener('keydown', (e) => {
    if (!isSlideshowActive) return;
    
    if (e.key === 'ArrowRight' || e.key === ' ') {
        e.stopPropagation();
        nextPdfPage();
    } else if (e.key === 'ArrowLeft') {
        e.stopPropagation();
        prevPdfPage();
    } else if (e.key === 'Escape') {
        e.stopPropagation();
        exitPdfSlideshow();
    }
});

// Click to navigate in slideshow
document.getElementById('pdfSlideshowContainer').addEventListener('click', (e) => {
    if (!isSlideshowActive) return;
    // If clicking on a button, don't trigger page change
    if (e.target.closest('.slideshow-btn')) return;
    
    const width = window.innerWidth;
    if (e.clientX > width / 2) {
        nextPdfPage();
    } else {
        prevPdfPage();
    }
});

// Auto-hide controls on mouse move
document.getElementById('pdfSlideshowContainer').addEventListener('mousemove', () => {
    if (!isSlideshowActive) return;
    
    const controls = document.querySelector('.slideshow-controls');
    if (controls) {
        controls.classList.add('show-controls');
        document.body.style.cursor = 'default';
        
        if (slideshowTimer) clearTimeout(slideshowTimer);
        slideshowTimer = setTimeout(() => {
            if (isSlideshowActive) {
                controls.classList.remove('show-controls');
                document.body.style.cursor = 'none';
            }
        }, 3000);
    }
});

// Handle window resize for slideshow
window.addEventListener('resize', () => {
    if (isSlideshowActive) {
        renderSlideshowPage();
    }
});

function navigateMedia(d) { if (mediaItems.length <= 1) return; currentMediaIndex = (currentMediaIndex + d + mediaItems.length) % mediaItems.length; loadMedia(); }
function uiConfirm(msg, ok) { 
    const text = document.getElementById('confirmText');
    const btn = document.getElementById('confirmOkBtn');
    if (text && btn) {
        text.innerText = msg; 
        btn.onclick = () => { ok(); closeModal('confirmModal'); }; 
        openModal('confirmModal'); 
    } else {
        if (confirm(msg)) ok();
    }
}
function uiAlert(msg) { 
    const text = document.getElementById('alertText');
    if (text) {
        text.innerText = msg; 
        openModal('alertModal'); 
    } else {
        alert(msg);
    }
}

async function submitNewFolder() { 
    const n = document.getElementById('newFolderName').value.trim(); if (!n) return;
    
    // Optimistic UI
    if (currentItems.find(i => i.name === n)) {
        uiAlert("Folder already exists.");
        return;
    }

    const optimisticFolder = {
        name: n,
        path: currentDir + (currentDir ? '/' : '') + n,
        isDir: true,
        size_f: '-',
        type: 'folder',
        mtime_f: 'Just now',
        isCreating: true
    };

    currentItems.unshift(optimisticFolder);
    totalItems++;
    renderExplorer();
    
    closeModal('folderModal');
    document.getElementById('newFolderName').value = "";

    const fd = new FormData(); fd.append('newfolder', n); 
    try {
        let url = `?ajax=1&dir=${encodeURIComponent(currentDir)}`;
        if (isSharedView) {
            const shareToken = new URLSearchParams(window.location.search).get('share');
            url = `?share=${shareToken}&ajax=1&dir=${encodeURIComponent(currentDir)}`;
        }
        const response = await fetch(url, {method:'POST', body:fd});
        const res = await response.json();
        if (res.success) { 
            await fetchExplorer(currentDir, currentSearch, currentPage, false, true); 
            showSnackbar(`Folder "${n}" created.`);
        } else {
            uiAlert("Folder already exists or invalid name.");
            await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
        }
    } catch(e) { 
        uiAlert("Error creating folder."); 
        await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
    }
}
function handleSearchKeyUp(e) { 
    const storageBtn = document.getElementById('storageBtn'); 
    const myFilesBtn = document.getElementById('myFilesBtn'); 
    if(e.key === 'Enter') {
        fetchExplorer(currentDir, e.target.value.trim(), 1); 
        if (storageBtn) storageBtn.style.background = ""; 
        if (myFilesBtn) myFilesBtn.style.background = "";
        const explorerBody = document.querySelector('.explorer-body');
        if (explorerBody) explorerBody.scrollTop = 0;
    } 
}
function clearSearch() { const myFilesBtn = document.getElementById('myFilesBtn'); if (myFilesBtn) myFilesBtn.style.background = "#eee"; document.getElementById('searchInput').value = ''; fetchExplorer(currentDir, '', 1); }
async function abortUpload() { if(currentXhr) { currentXhr.abort(); currentXhr = null; } document.getElementById('uploadStatusCard').style.display = 'none'; await fetchExplorer(currentDir, currentSearch, currentPage, true, true); showSnackbar("Upload cancelled."); }

function submitBulkDelete() {
    const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
    if (!checked.length) return;
    
    uiConfirm(`Delete ${checked.length} items?`, async () => {
        // Optimistic UI: Mark as deleting
        currentItems.forEach(item => {
            if (checked.includes(item.path)) {
                item.isDeleting = true;
            }
        });
        renderExplorer();
        
        const fd = new FormData(); 
        fd.append('bulk_delete', '1'); 
        checked.forEach(v => fd.append('selected_items[]', v));
        
        try {
            let url = `?ajax=1&dir=${encodeURIComponent(currentDir)}`;
            if (isSharedView) {
                const shareToken = new URLSearchParams(window.location.search).get('share');
                url = `?share=${shareToken}&ajax=1&dir=${encodeURIComponent(currentDir)}`;
            }
            const res = await fetch(url, {method:'POST', body:fd});
            const resJson = await res.json();
            if (!res.ok) throw new Error("Bulk delete failed");
            await fetchExplorer(currentDir, currentSearch, currentPage, false, true);
            showSnackbar(`${checked.length} items deleted.`);
        } catch (e) {
            uiAlert("Failed to delete some items.");
            await fetchExplorer(currentDir, currentSearch, currentPage, true, true); // Restore list
        }
    });
}

function submitBulkZip() {
    const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
    if (!checked.length) return;
    
    let action = window.location.pathname;
    if (isSharedView) {
        const shareToken = new URLSearchParams(window.location.search).get('share');
        action += `?share=${shareToken}`;
    }
    
    const form = document.createElement('form'); form.method = 'POST'; form.action = action;
    const zipI = document.createElement('input'); zipI.type = 'hidden'; zipI.name = 'bulk_zip'; zipI.value = '1'; form.appendChild(zipI);
    checked.forEach(v => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'selected_items[]'; i.value = v; form.appendChild(i); });
    document.body.appendChild(form); form.submit();
}

function renamePrompt() {
    const title = document.getElementById('promptTitle');
    const input = document.getElementById('promptInput');
    const btn = document.getElementById('promptOkBtn');
    const text = document.getElementById('promptText');
    
    if (title) title.innerText = "Rename"; 
    if (text) text.innerText = `New name for "${selectedName}":`; 
    if (input) input.value = selectedName;
    
    if (btn) {
        btn.onclick = async () => {
            const n = document.getElementById('promptInput').value.trim(); if (!n) return;
            const fd = new FormData(); fd.append('rename_old', selectedPath); fd.append('rename_new', n);
            try {
                const res = await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd});
                const result = await res.json();
                if (result.success) {
                    await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
                    showSnackbar(`Renamed to "${n}"`);
                } else {
                    showSnackbar(`Rename failed: ${result.error || 'Unknown error'}`, { duration: 5000 });
                }
            } catch (e) {
                showSnackbar("Rename error occurred.", { duration: 5000 });
            }
            closeModal('promptModal');
        };
    }
    openModal('promptModal');
}

async function sharePrompt() {
    const isDir = currentItems.find(i => i.path === selectedPath)?.isDir;
    const uploadOption = document.getElementById('shareUploadOption');
    const allowUploadSelect = document.getElementById('shareAllowUpload');
    
    if (isDir) {
        uploadOption.style.display = '';
        allowUploadSelect.value = "0";
    } else {
        uploadOption.style.display = 'none';
    }

    const textElement = document.getElementById('sharePermissionText');
    textElement.style.color = "gray";

    const fd = new FormData();
    fd.append('action', 'create_share');
    fd.append('path', selectedPath);
    fd.append('allow_upload', '0');

    try {
        const res = await (await fetch('?ajax=1', {method: 'POST', body: fd})).json();
        if (res.success && res.token) {
            const shareUrl = `${window.location.origin}${window.location.pathname}?share=${res.token}`;
            document.getElementById('shareLinkInput').value = shareUrl;
            openModal('shareModal');
        } else {
            uiAlert(res.error || "Could not create share link.");
        }
    } catch (e) {
        uiAlert("An error occurred while creating the share link.");
    }
}

async function updateShareLink() {
    const allowUpload = document.getElementById('shareAllowUpload').value;
    const fd = new FormData();
    fd.append('action', 'create_share');
    fd.append('path', selectedPath);
    fd.append('allow_upload', allowUpload);

    try {
        const res = await (await fetch('?ajax=1', {method: 'POST', body: fd})).json();
        if (res.success && res.token) {
            const shareUrl = `${window.location.origin}${window.location.pathname}?share=${res.token}`;
            document.getElementById('shareLinkInput').value = shareUrl;
            const button = document.getElementById('copyShareBtn');
            if (button) button.textContent = 'Copy';
        }
    } catch (e) {
        console.error("Error updating share link:", e);
    }


    const textElement = document.getElementById('sharePermissionText');
    // Toggle color based on selection value
    if (allowUpload === "1") {
        textElement.style.color = "black";
    } else {
        textElement.style.color = "gray";
    }
}

function copyShareLink() {
    const input = document.getElementById('shareLinkInput');
    const button = document.getElementById('copyShareBtn');
    if (input) {
        input.select();
        document.execCommand('copy');
    }
    if (button) {
        button.textContent = 'Copied!';
        setTimeout(() => { button.textContent = 'Copy'; }, 2000);
    }
    showSnackbar("Link copied to clipboard.");
}

let moveModalDir = "";
let moveModalSelectedPath = "";

async function movePrompt() {
    const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked'));
    const movingItems = [], hasSelection = checked.length > 0;
    const title = document.getElementById('moveModalTitle');
    const btn = document.getElementById('moveModalConfirmBtn');

    if (hasSelection) { 
        checked.forEach(c => movingItems.push(c.value)); 
        if (title) title.innerText = `Move ${movingItems.length} Item(s)`; 
    }
    else if (selectedPath) { 
        movingItems.push(selectedPath); 
        if (title) title.innerText = `Move "${selectedName}"`; 
    }
    else { uiAlert("No item selected to move."); return; }

    moveModalDir = ""; // Start at root
    moveModalSelectedPath = "";
    
    await renderMoveModal();
    
    if (btn) {
        btn.onclick = async () => {
            closeModal('moveModal');

            const targetPath = moveModalSelectedPath;
            const fd = new FormData();
            if (hasSelection) {
                fd.append('bulk_move', '1'); fd.append('move_target', targetPath);
                movingItems.forEach(itemPath => fd.append('selected_items[]', itemPath));
            } else {
                fd.append('move_file', movingItems[0]); fd.append('move_target', targetPath);
            }
            try {
                movingItems.forEach(path => {
                    const item = currentItems.find(i => i.path === path);
                    if (item) item.isMoving = true;
                });
                renderExplorer();
                const result = await (await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, { method:'POST', body:fd })).json();
                if(result.error) {
                    uiAlert(`Move failed: ${result.error}`);
                } else {
                    await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
                    showSnackbar(`Moved ${movingItems.length} item(s) successfully.`);
                }
            } catch(e) { 
                uiAlert("An error occurred during the move operation."); 
                await fetchExplorer(currentDir, currentSearch, currentPage, true, true);
            }
            // finally { closeModal('moveModal'); }
        };
    }
    openModal('moveModal');
}

async function renderMoveModal() {
    const list = document.getElementById('moveModalList');
    
    try {
        const response = await fetch(`?dir=${encodeURIComponent(moveModalDir)}&ajax=1`);
        const data = await response.json();
        const items = data.items;
        
        let html = "";
        
        // Root folder option
        const isRootSelected = moveModalSelectedPath === '';
        html += `
            <div class="move-modal-item ${isRootSelected ? 'selected' : ''}" 
                 data-path=""
                 onclick="event.stopPropagation(); moveModalSelect('')"
                 ondblclick="event.stopPropagation(); moveModalNavigate('')">
                <div class="folder-icon">🏠</div>
                <div style="font-weight: 500;">/Root</div>
            </div>
        `;

        // Go up option
        if (moveModalDir !== "") {
            const parent = moveModalDir.split('/').slice(0, -1).join('/');
            html += `
                <div class="move-modal-item" onclick="event.stopPropagation(); moveModalNavigate('${escapeJs(parent)}')">
                    <div class="up-icon">⤴</div>
                    <div style="font-weight: 500;">..</div>
                </div>
            `;
        }
        
        items.forEach(f => {
            const isSelected = moveModalSelectedPath === f.path;
            const isDir = f.isDir;
            html += `
                <div class="move-modal-item ${isSelected ? 'selected' : ''}" 
                     data-path="${escapeHtml(f.path)}"
                     onclick="event.stopPropagation(); moveModalSelect('${escapeJs(f.path)}')"
                     ${isDir ? `ondblclick="event.stopPropagation(); moveModalNavigate('${escapeJs(f.path)}')"` : ''}>
                    <div class="folder-icon">${getFileIcon(f)}</div>
                    <div style="font-weight: 500; ${isDir ? '' : 'color: silver;'}">${escapeHtml(f.name)}</div>
                </div>
            `;
        });
        
        if (html === "" && moveModalDir === "") {
            html = `<div style="padding:20px; text-align:center; color:#999;">No folders found in root.</div>`;
        }
        
        list.innerHTML = html;
        
    } catch (e) {
        console.error(e);
        list.innerHTML = `<div style="padding:20px; text-align:center; color:red;">Failed to load folders.</div>`;
    }
}

function moveModalNavigate(path) {
    moveModalDir = path;
    moveModalSelectedPath = path; 
    renderMoveModal();
}

function moveModalSelect(path) {
    moveModalSelectedPath = path;
    
    const items = document.querySelectorAll('.move-modal-item');
    items.forEach(item => {
        if (item.getAttribute('data-path') === path) {
            item.classList.add('selected');
        } else {
            item.classList.remove('selected');
        }
    });
}

async function fetchFullIndex() {
    if (isSharedView) return;
    try {
        const response = await fetch('?action=get_full_index', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        fullIndex = await response.json();
    } catch (e) {
        console.error("Failed to fetch full index", e);
    }
}