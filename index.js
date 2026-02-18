// --- STATE VARIABLES ---
// These are initialized by the inline script in index.php
let currentDir = "";
let currentSearch = "";
let currentItems = [];
let currentPage = 1;
let totalItems = 0;
let perPage = 50;
let sortKey = 'name';
let sortOrder = 1;

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
function downloadFile(path) {
    const link = document.createElement('a');
    link.href = `?download=${encodeURIComponent(path)}&force=1`;
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
    // Do not push state again during popstate
    fetchExplorer(dir, search, page, false);
};

function toggleSidebar(active) {
    const sidebar = document.getElementById('sidebar');
    if (active) sidebar.classList.add('active');
    else sidebar.classList.remove('active');
}

// --- GLOBAL KEYBOARD EVENTS ---
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
    const items = currentItems;

    let bcHtml = currentSearch ? `🔍 Search: "<strong>${escapeHtml(currentSearch)}</strong>"` : `<a class="bc-link" onclick="fetchExplorer('', '', 1)">Root</a>`;
    if (!currentSearch) {
        let cum = "";
        currentDir.split('/').filter(p => p).forEach(p => {
            cum += (cum ? '/' : '') + p;
            bcHtml += ` <span class="bc-sep">/</span> <a class="bc-link" onclick="fetchExplorer('${escapeJs(cum)}', '', 1)">${escapeHtml(p)}</a>`;
        });
    }
    bc.innerHTML = bcHtml;

    const totalPages = currentSearch ? Math.ceil(totalItems / perPage) : 1;
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
        html += `<div class="table-row folder" onclick="fetchExplorer('${escapeJs(parent)}', '', 1)"><div class="col"></div><div class="col-name"><span class="icon">⤴️</span> ..</div></div>`;
    }

    items.forEach(f => {
        const isMedia = !f.isDir && viewableExts.includes(f.type.toLowerCase());
        const action = f.isDir ? `fetchExplorer('${escapeJs(f.path)}', '', 1)` : (isMedia ? `openMediaViewer('${escapeJs(f.path)}')` : `downloadFile('${escapeJs(f.path)}')`);
        
        let downloadBtn = !f.isDir ? `<span class="action-icon download-btn" onclick="event.stopPropagation(); downloadFile('${escapeJs(f.path)}')" title="Download">📥</span>` : '';

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
            <div style="text-align:right; display:flex; justify-content: flex-end; gap: 8px;">
                ${downloadBtn}
                <span class="action-icon delete-btn" onclick="event.stopPropagation(); deleteItem('${escapeJs(f.path)}', '${escapeJs(f.name)}')" title="Delete">🗑️</span>
            </div>
        </div>`;
    });
    list.innerHTML = html || `<div style="padding:30px; text-align:center; color:#999; font-size:0.9rem;">${currentSearch ? 'No matches found.' : 'Folder empty.'}</div>`;
    document.getElementById('selectAll').checked = false;
    updateBulkBtn();
}

async function fetchExplorer(dir, search = "", page = 1, updateHistory = true) {
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
        document.getElementById('searchInput').value = currentSearch;

        if (updateHistory) {
            const searchParams = new URLSearchParams();
            if (currentDir) searchParams.set('dir', currentDir);
            if (currentSearch) searchParams.set('search', currentSearch);
            if (currentPage > 1) searchParams.set('page', currentPage);
            
            const newUrl = window.location.pathname + (searchParams.toString() ? '?' + searchParams.toString() : '');
            window.history.pushState({ dir: currentDir, search: currentSearch, page: currentPage }, '', newUrl);
        }
    } catch (e) { console.error(e); uiAlert("Load failed."); }
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
            if (currentXhr) break;
        }
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
        const items = e.dataTransfer.items;
        if (!items) return;

        const initialEntries = [];
        for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry();
            if (entry) initialEntries.push(entry);
        }

        let filesToUpload = [];
        async function getFilesFromEntry(entry, path = "") {
            if (entry.isFile) {
                return new Promise(resolve => entry.file(file => { 
                    filesToUpload.push({ file, relativePath: path + file.name }); 
                    resolve(); 
                }));
            } else if (entry.isDirectory) {
                const dirReader = entry.createReader();
                let allEntries = [];
                const readMore = async () => {
                    return new Promise(resolve => {
                        dirReader.readEntries(async results => {
                            if (results.length > 0) {
                                allEntries = allEntries.concat(results);
                                await readMore();
                                resolve();
                            } else {
                                resolve();
                            }
                        });
                    });
                };
                await readMore();
                for (const childEntry of allEntries) await getFilesFromEntry(childEntry, path + entry.name + "/");
            }
        }

        for (const entry of initialEntries) await getFilesFromEntry(entry);
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
    fetchExplorer(currentDir, currentSearch, 1); 
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
    const moveBtn = document.getElementById('bulkMoveBtn');
    const zipBtn = document.getElementById('bulkZipBtn');

    if (checkedCount > 0) {
        bulkActions.style.display = 'flex';
        deleteBtn.innerHTML = `🗑️ Delete (${checkedCount})`;
        moveBtn.innerHTML = `➡️ Move (${checkedCount})`;
        zipBtn.innerHTML = `📦 ZIP (${checkedCount})`;
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
    
    if (x + 200 > window.innerWidth) x -= 200;
    if (y + 150 > window.innerHeight) y -= 150;

    menu.style.left = x + 'px'; 
    menu.style.top = y + 'px'; 
    menu.style.display = 'block'; 
}
document.addEventListener('click', () => document.getElementById('contextMenu').style.display = 'none');

function openMediaViewer(path) {
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
                    <div style="width:125%; text-align:center;">
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

function abortUpload() { 
    if(currentXhr) {
        currentXhr.abort();
        currentXhr = null;
    }
    document.getElementById('uploadStatusCard').style.display = 'none';
    fetchExplorer(currentDir, currentSearch, currentPage); 
}

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
    const checked = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked'));
    const movingItems = [];
    const hasSelection = checked.length > 0;

    // If there is a selection, move the selected items. Otherwise, move the single item that was right-clicked.
    if (hasSelection) {
        checked.forEach(c => movingItems.push(c.value));
        document.getElementById('promptTitle').innerText = `Move ${movingItems.length} Item(s)`;
    } else if (selectedPath) {
        movingItems.push(selectedPath);
        document.getElementById('promptTitle').innerText = `Move "${selectedName}"`;
    } else {
        uiAlert("No item selected to move.");
        return;
    }

    document.getElementById('promptText').innerText = "Enter target folder path (e.g., Folder/Subfolder). Leave empty to move to root.";
    document.getElementById('promptInput').value = "";
    
    document.getElementById('promptOkBtn').onclick = async () => {
        const targetPath = document.getElementById('promptInput').value.trim();
        const fd = new FormData();

        // Use bulk move if there's a selection, otherwise use single move
        if (hasSelection) {
            fd.append('bulk_move', '1');
            fd.append('move_target', targetPath);
            movingItems.forEach(itemPath => fd.append('selected_items[]', itemPath));
        } else {
            fd.append('move_file', movingItems[0]);
            fd.append('move_target', targetPath);
        }

        try {
            const response = await fetch(`?ajax=1&dir=${encodeURIComponent(currentDir)}`, { method:'POST', body:fd });
            const result = await response.json();
            if(result.error) {
                uiAlert(`Move failed: ${result.error}`);
            }
        } catch(e) {
            console.error("Move operation failed:", e);
            uiAlert("An error occurred during the move operation.");
        } finally {
            closeModal('promptModal');
            fetchExplorer(currentDir, currentSearch, currentPage);
        }
    };
    
    openModal('promptModal');
}