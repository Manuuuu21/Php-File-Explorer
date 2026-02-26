// --- STATE VARIABLES ---
let currentDir = "";
let currentSearch = "";
let currentItems = [];
let currentPage = 1;
let totalItems = 0;
let perPage = 50;
let sortKey = 'name';
let sortOrder = 1;
let isSharedView = false;
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

let storageLimit = `100 GB`;

const viewableExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'txt', 'html', 'css', 'php', 'js'];

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
                <span style="color: #666; font-size: 1.2rem;">📄</span>
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
        if (isSharedView) return;
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
        if (isSharedView) return;
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
        if (isSharedView) return;
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
        if (isSharedView || clipboardItems.length === 0) return;
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
        html += `<div class="table-row folder" style="position:sticky;top:40px;background:white;z-index:1;" onclick="fetchExplorer('${escapeJs(parent)}', '', 1)"><div class="col"></div><div class="col-name"><span class="icon">⤴️</span> ..</div></div>`;
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
        const isUploading = f.isUploading || f.isCreating;
        
        let downloadBtn = (!f.isDir && !isUploading) ? `<span class="action-icon download-btn" onclick="event.stopPropagation(); downloadFile('${escapeJs(f.path)}')" title="Download">📥</span>` : '';
        let contextMenu = (isSharedView || isUploading) ? '' : `oncontextmenu="handleContextMenu(event, this)"`;
        let checkbox = isSharedView ? `<div style="width:56px"></div>` : `<div style="text-align:center"><input type="checkbox" name="selected_items[]" value="${escapeHtml(f.path)}" ${isActive ? 'checked' : ''} ${isUploading ? 'disabled' : ''} onclick="event.stopPropagation(); updateBulkBtn()"></div>`;
        let actions = (isSharedView || isUploading) ? downloadBtn : `${downloadBtn} <span class="action-icon delete-btn" onclick="event.stopPropagation(); deleteItem('${escapeJs(f.path)}', '${escapeJs(f.name)}')" title="Delete">🗑️</span>`;

        let rowStyle = isUploading ? 'opacity: 0.6; pointer-events: none;' : '';
        let nameContent = f.isUploading ? `<strong>${escapeHtml(f.name)}</strong> <span data-upload-progress="${escapeHtml(f.name)}" style="font-size:0.7rem; color:var(--primary);">Uploading... ${f.progress || 0}%</span>` : 
                          f.isCreating ? `<strong>${escapeHtml(f.name)}</strong> <span style="font-size:0.7rem; color:var(--primary);">Creating...</span>` :
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
                <span class="icon">${isUploading ? '⏳' : (f.isDir ? '📁' : '📄')}</span>
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
    if (!isSharedView) {
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
    const snackbar = document.createElement('div');
    snackbar.className = 'snackbar';
    
    let actionsHtml = '';
    if (options.actionText) {
        actionsHtml = `<button class="snackbar-btn" onclick="${options.actionCallback || ''}">${options.actionText}</button>`;
    }
    
    snackbar.innerHTML = `
        <div class="snackbar-content">${message}</div>
        <div class="snackbar-actions">
            ${actionsHtml}
            <button class="snackbar-close" onclick="this.parentElement.parentElement.classList.add('out'); setTimeout(() => this.parentElement.parentElement.remove(), 300)">✕</button>
        </div>
    `;
    
    container.appendChild(snackbar);
    
    if (!options.persistent) {
        setTimeout(() => {
            if (snackbar.parentElement) {
                snackbar.classList.add('out');
                setTimeout(() => snackbar.remove(), 300);
            }
        }, options.duration || 5000);
    }
    
    return snackbar;
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
        const result = await (await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, { method:'POST', body:fd })).json();
        
        transferSnackbar.classList.add('out');
        setTimeout(() => transferSnackbar.remove(), 300);

        if(result.error) {
            uiAlert(`Paste failed: ${result.error}`);
        } else {
            const actionText = clipboardAction === 'cut' ? 'moved' : 'copied';
            showSnackbar(`${count} files have been ${actionText} from ${sourceFolder} to "${destFolder}"`, { actionText: '' });
            if (clipboardAction === 'cut') {
                clipboardItems = [];
                clipboardSourceDir = "";
            }
            fetchExplorer(currentDir, currentSearch, currentPage);
        }
    } catch(e) { 
        transferSnackbar.classList.add('out');
        setTimeout(() => transferSnackbar.remove(), 300);
        uiAlert("An error occurred during the paste operation."); 
    }
}

async function fetchExplorer(dir, search = "", page = 1, updateHistory = true) {
    try {
        let url = `?dir=${encodeURIComponent(dir)}&search=${encodeURIComponent(search)}&page=${page}&sort=${sortKey}&order=${sortOrder}&ajax=1`;
        if (isSharedView) {
            const shareToken = new URLSearchParams(window.location.search).get('share');
            url = `?share=${shareToken}&dir=${encodeURIComponent(dir)}&page=${page}&sort=${sortKey}&order=${sortOrder}&ajax=1`;
        }
        
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await response.json();
        
        activePaths = [];
        lastClickTime = 0;
        lastClickPath = null;
        currentDir = data.dir; 
        currentSearch = data.search; 
        currentItems = data.items;
        currentPage = data.page;
        totalItems = data.totalCount;
        perPage = data.perPage;

        renderExplorer();
        if (!isSharedView) {
            updateStats(data.stats);
            document.getElementById('clearSearchBtn').style.display = currentSearch ? 'inline' : 'none';
            document.getElementById('searchInput').value = currentSearch;
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

    const isStorageView = document.getElementById('storageView').style.display === 'flex';
    
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
    batch.forEach(item => {
        const fileName = item.relativePath || item.file.name;
        // Check if already exists to avoid duplicates
        if (!currentItems.find(i => i.name === fileName)) {
            currentItems.unshift({
                name: fileName,
                path: (isStorageView ? "" : currentDir) + ((isStorageView ? "" : currentDir) ? '/' : '') + fileName,
                isDir: item.file.webkitRelativePath ? true : false,
                size_f: formatBytes(item.file.size),
                type: item.file.name.split('.').pop() || 'file',
                mtime_f: 'Just now',
                isUploading: true,
                progress: 0
            });
            totalItems++;
        }
    });
    renderExplorer();

    card.style.display = 'flex';
    
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
                    const uploadingItem = currentItems.find(it => it.name === fileName && it.isUploading);
                    if (uploadingItem) {
                        uploadingItem.progress = percent;
                        const progressSpan = document.querySelector(`[data-upload-progress="${escapeHtml(fileName)}"]`);
                        if (progressSpan) {
                            progressSpan.innerText = `Uploading... ${percent}%`;
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
            xhr.open('POST', `${window.location.pathname}?dir=${encodeURIComponent(uploadDir)}&action=upload&ajax=1`, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        });
        try { 
            await uploadPromise; 
            // Mark as done uploading but wait for full refresh
        } catch (e) { 
            if (currentXhr) break; 
        }
    }
    card.style.display = 'none';
    currentXhr = null;

    if (isStorageView) {
        await fetchExplorer('', '', 1);
    } else {
        await fetchExplorer(currentDir, currentSearch, currentPage);
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
            myFilesBtn.style.background = "";
            
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
            storageBtn.style.background = "";
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
        }
    }, 300); 
}

function deleteItem(path, name) { 
    uiConfirm(`Delete "${name}"?`, async () => { 
        // Optimistic UI: Remove from list immediately
        const index = currentItems.findIndex(i => i.path === path);
        if (index !== -1) {
            currentItems.splice(index, 1);
            totalItems--;
            renderExplorer();
        }
        
        try {
            const res = await fetch(`?delete=${encodeURIComponent(path)}&ajax=1`);
            if (!res.ok) throw new Error("Delete failed");
            // Refresh stats and actual list in background
            fetchExplorer(currentDir, currentSearch, currentPage, false);
        } catch (e) {
            uiAlert(`Failed to delete "${name}".`);
            fetchExplorer(currentDir, currentSearch, currentPage); // Restore list
        }
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
    if (checkedCount > 0) {
        bulkActions.style.display = 'flex';
        document.getElementById('bulkDeleteBtn').innerHTML = `🗑️ Delete (${checkedCount})`;
        document.getElementById('bulkMoveBtn').innerHTML = `➡️ Move (${checkedCount})`;
        document.getElementById('bulkZipBtn').innerHTML = `📦 ZIP (${checkedCount})`;

        document.getElementById('m-dropdown-bulkDeleteBtn').innerHTML = `🗑️ Delete (${checkedCount})`;
        document.getElementById('m-dropdown-bulkMoveBtn').innerHTML = `➡️ Move (${checkedCount})`;
        document.getElementById('m-dropdown-bulkZipBtn').innerHTML = `📦 ZIP (${checkedCount})`;
    } else {
        bulkActions.style.display = 'none';
    }
}

function handleContextMenu(e, el) { 
    e.preventDefault(); 
    selectedPath = el.dataset.path; selectedName = el.dataset.name; 
    const menu = document.getElementById('contextMenu'); 
    let x = e.pageX, y = e.pageY;
    if (x + 200 > window.innerWidth) x -= 200;
    if (y + 150 > window.innerHeight) y -= 150;
    menu.style.left = x + 'px'; menu.style.top = y + 'px'; menu.style.display = 'block'; 
}
document.addEventListener('click', () => { if (document.getElementById('contextMenu')) document.getElementById('contextMenu').style.display = 'none' });

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
        } else {
            container.innerHTML = `<img src="${url}" style="max-height:70vh; max-width:100%">`;
        }
        container.style.opacity = '1';
    }, 150);
}

async function renderPdf(url) {
    const container = document.getElementById('pdfViewer');
    try {
        const loadingTask = pdfjsLib.getDocument(url);
        const pdf = await loadingTask.promise;
        container.innerHTML = ''; // Clear loading message
        
        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
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

function navigateMedia(d) { if (mediaItems.length <= 1) return; currentMediaIndex = (currentMediaIndex + d + mediaItems.length) % mediaItems.length; loadMedia(); }
function uiConfirm(msg, ok) { document.getElementById('confirmText').innerText = msg; document.getElementById('confirmOkBtn').onclick = () => { ok(); closeModal('confirmModal'); }; openModal('confirmModal'); }
function uiAlert(msg) { document.getElementById('alertText').innerText = msg; openModal('alertModal'); }
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
        const response = await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd});
        const res = await response.json();
        if (res.success) { 
            fetchExplorer(currentDir, currentSearch, currentPage, false); 
        } else {
            uiAlert("Folder already exists or invalid name.");
            fetchExplorer(currentDir, currentSearch, currentPage);
        }
    } catch(e) { 
        uiAlert("Error creating folder."); 
        fetchExplorer(currentDir, currentSearch, currentPage);
    }
}
function handleSearchKeyUp(e) { if(e.key === 'Enter') fetchExplorer(currentDir, e.target.value.trim(), 1); }
function clearSearch() { document.getElementById('searchInput').value = ''; fetchExplorer(currentDir, '', 1); }
function abortUpload() { if(currentXhr) { currentXhr.abort(); currentXhr = null; } document.getElementById('uploadStatusCard').style.display = 'none'; fetchExplorer(currentDir, currentSearch, currentPage); }

function submitBulkDelete() {
    const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
    if (!checked.length) return;
    
    uiConfirm(`Delete ${checked.length} items?`, async () => {
        // Optimistic UI: Remove from list immediately
        currentItems = currentItems.filter(i => !checked.includes(i.path));
        totalItems -= checked.length;
        renderExplorer();
        
        const fd = new FormData(); 
        fd.append('bulk_delete', '1'); 
        checked.forEach(v => fd.append('selected_items[]', v));
        
        try {
            const res = await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd});
            if (!res.ok) throw new Error("Bulk delete failed");
            fetchExplorer(currentDir, currentSearch, currentPage, false);
        } catch (e) {
            uiAlert("Failed to delete some items.");
            fetchExplorer(currentDir, currentSearch, currentPage); // Restore list
        }
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
        const n = document.getElementById('promptInput').value.trim(); if (!n) return;
        const fd = new FormData(); fd.append('rename_old', selectedPath); fd.append('rename_new', n);
        await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd}); 
        closeModal('promptModal'); fetchExplorer(currentDir, currentSearch, currentPage);
    };
    openModal('promptModal');
}

async function sharePrompt() {
    const fd = new FormData();
    fd.append('action', 'create_share');
    fd.append('path', selectedPath);

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

function copyShareLink() {
    const input = document.getElementById('shareLinkInput');
    const button = document.getElementById('copyShareBtn');
    input.select();
    document.execCommand('copy');
    button.textContent = 'Copied!';
    setTimeout(() => { button.textContent = 'Copy'; }, 2000);
}


function movePrompt() {
    const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked'));
    const movingItems = [], hasSelection = checked.length > 0;
    if (hasSelection) { checked.forEach(c => movingItems.push(c.value)); document.getElementById('promptTitle').innerText = `Move ${movingItems.length} Item(s)`; }
    else if (selectedPath) { movingItems.push(selectedPath); document.getElementById('promptTitle').innerText = `Move "${selectedName}"`; }
    else { uiAlert("No item selected to move."); return; }

    document.getElementById('promptText').innerText = "Enter target folder path (e.g., Folder/Subfolder). Leave empty to move to root.";
    document.getElementById('promptInput').value = "";
    document.getElementById('promptOkBtn').onclick = async () => {
        const targetPath = document.getElementById('promptInput').value.trim();
        const fd = new FormData();
        if (hasSelection) {
            fd.append('bulk_move', '1'); fd.append('move_target', targetPath);
            movingItems.forEach(itemPath => fd.append('selected_items[]', itemPath));
        } else {
            fd.append('move_file', movingItems[0]); fd.append('move_target', targetPath);
        }
        try {
            const result = await (await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, { method:'POST', body:fd })).json();
            if(result.error) uiAlert(`Move failed: ${result.error}`);
        } catch(e) { uiAlert("An error occurred during the move operation."); }
        finally { closeModal('promptModal'); fetchExplorer(currentDir, currentSearch, currentPage); }
    };
    openModal('promptModal');
}