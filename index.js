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
let activePaths = [];
let lastClickTime = 0;
let lastClickPath = null;

// --- INTERNAL STATE ---
let selectedPath = null;
let selectedName = null;
let currentXhr = null;
let mediaItems = [];
let currentMediaIndex = -1;

const viewableExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav'];

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
    
    // CTRL+X (Cut)
    if (e.ctrlKey && e.key.toLowerCase() === 'x') {
        if (isSharedView) return;
        const selected = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(c => c.value);
        if (selected.length > 0) {
            clipboardItems = selected;
            clipboardSourceDir = currentDir;
            renderExplorer(); // Update opacity
            showSnackbar(`${selected.length} items cut to clipboard`, { actionText: '', actionUrl: '#' });
        } else if (activePaths.length > 0) {
            clipboardItems = [...activePaths];
            clipboardSourceDir = currentDir;
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

function renderExplorer() {
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

        const isCut = clipboardItems.includes(f.path);
        const isActive = activePaths.includes(f.path);
        
        let downloadBtn = !f.isDir ? `<span class="action-icon download-btn" onclick="event.stopPropagation(); downloadFile('${escapeJs(f.path)}')" title="Download">📥</span>` : '';
        let contextMenu = isSharedView ? '' : `oncontextmenu="handleContextMenu(event, this)"`;
        let checkbox = isSharedView ? `<div style="width:56px"></div>` : `<div style="text-align:center"><input type="checkbox" name="selected_items[]" value="${escapeHtml(f.path)}" ${isActive ? 'checked' : ''} onclick="event.stopPropagation(); updateBulkBtn()"></div>`;
        let actions = isSharedView ? downloadBtn : `${downloadBtn} <span class="action-icon delete-btn" onclick="event.stopPropagation(); deleteItem('${escapeJs(f.path)}', '${escapeJs(f.name)}')" title="Delete">🗑️</span>`;

        html += `
        <div class="table-row ${f.isDir ? 'folder' : ''} ${isCut ? 'cut-item' : ''} ${isActive ? 'active-row' : ''}" 
             ${contextMenu} 
             data-path="${escapeHtml(f.path)}" 
             data-name="${escapeHtml(f.name)}"
             onclick="handleRowClick(event, '${escapeJs(f.path)}', ${f.isDir ? `'dir'` : `'file'`})">
            ${checkbox}
            <div class="col-name">
                <span class="icon">${f.isDir ? '📁' : '📄'}</span>
                <div style="display: flex; flex-direction: column; min-width: 0;">
                    <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(f.name)}</strong>
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
    fd.append('bulk_move', '1');
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
            showSnackbar(`${count} files have been moved from ${sourceFolder} to "${destFolder}"`, { actionText: '' });
            clipboardItems = [];
            clipboardSourceDir = "";
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
            if (dir) searchParams.set('dir', dir); else searchParams.delete('dir');
            if (search) searchParams.set('search', search); else searchParams.delete('search');
            if (page > 1) searchParams.set('page', page); else searchParams.delete('page');
            
            const newUrl = window.location.pathname + '?' + searchParams.toString();
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
            xhr.open('POST', `${window.location.pathname}?dir=${encodeURIComponent(currentDir)}&action=upload&ajax=1`, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        });
        try { await uploadPromise; } catch (e) { if (currentXhr) break; }
    }
    card.style.display = 'none';
    currentXhr = null;
    fetchExplorer(currentDir, currentSearch, currentPage);
}

function setupDragAndDrop() {
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

function deleteItem(path, name) { uiConfirm(`Delete "${name}"?`, async () => { await fetch(`?delete=${encodeURIComponent(path)}&ajax=1`); fetchExplorer(currentDir, currentSearch, currentPage); }); }
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
    setTimeout(() => {
        if (['mp4','webm','ogg'].includes(ext)) container.innerHTML = `<video controls autoplay style="max-height:70vh; max-width:100%" src="${url}"></video>`;
        else if (['mp3','wav'].includes(ext)) {
            container.innerHTML = `<div class="audio-player-wrapper"><div class="vinyl-disc" id="vinylDisc"><div class="vinyl-label">🎵</div></div><div style="width:125%; text-align:center;"><audio id="mainAudio" controls autoplay src="${url}"></audio></div></div>`;
            const audio = document.getElementById('mainAudio'), disc = document.getElementById('vinylDisc');
            audio.onplay = () => disc.classList.add('playing');
            audio.onpause = audio.onended = () => disc.classList.remove('playing');
            if (!audio.paused) disc.classList.add('playing');
        } else container.innerHTML = `<img src="${url}" style="max-height:70vh; max-width:100%">`;
        container.style.opacity = '1';
    }, 150);
}

function navigateMedia(d) { if (mediaItems.length <= 1) return; currentMediaIndex = (currentMediaIndex + d + mediaItems.length) % mediaItems.length; loadMedia(); }
function uiConfirm(msg, ok) { document.getElementById('confirmText').innerText = msg; document.getElementById('confirmOkBtn').onclick = () => { ok(); closeModal('confirmModal'); }; openModal('confirmModal'); }
function uiAlert(msg) { document.getElementById('alertText').innerText = msg; openModal('alertModal'); }
async function submitNewFolder() { 
    const n = document.getElementById('newFolderName').value.trim(); if (!n) return;
    const fd = new FormData(); fd.append('newfolder', n); 
    try {
        const res = await (await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, {method:'POST', body:fd})).json();
        if (res.success) { closeModal('folderModal'); document.getElementById('newFolderName').value = ""; fetchExplorer(currentDir, currentSearch, currentPage); }
        else uiAlert("Folder already exists or invalid name.");
    } catch(e) { uiAlert("Error creating folder."); }
}
function handleSearchKeyUp(e) { if(e.key === 'Enter') fetchExplorer(currentDir, e.target.value.trim(), 1); }
function clearSearch() { document.getElementById('searchInput').value = ''; fetchExplorer(currentDir, '', 1); }
function abortUpload() { if(currentXhr) { currentXhr.abort(); currentXhr = null; } document.getElementById('uploadStatusCard').style.display = 'none'; fetchExplorer(currentDir, currentSearch, currentPage); }

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