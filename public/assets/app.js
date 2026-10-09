/* ============================================================
   auma-project-3 — логика интерфейса
   ============================================================ */
'use strict';

const BASE = (window.AUMA && window.AUMA.base) || '/';

const state = {
    view: 'dashboard',
    folder: '',
    filters: {},
    docs: [],
    folders: [],
    order: '',
    fmt: 'pdf',
    menuDocId: null,
    mailDocId: null
};

/* ---------------- Утилиты ---------------- */

const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function url(path, params) {
    const u = new URL(BASE.replace(/\/$/, '') + path, window.location.origin);
    Object.entries(params || {}).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) u.searchParams.set(k, v);
    });
    return u.toString();
}

async function api(path, { method = 'GET', body = null, params = null } = {}) {
    const res = await fetch(url(path, params), {
        method,
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify(body) : null,
        credentials: 'same-origin'
    });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
        if (res.status === 401) { window.location.href = BASE + 'login'; }
        throw new Error((data && data.error) || ('Ошибка ' + res.status));
    }
    return data;
}

function toast(message, kind = 'info', ms = 4200) {
    const box = $('#toasts');
    const el = document.createElement('div');
    el.className = 'toast ' + (kind === 'error' ? 'err' : kind === 'ok' ? 'ok' : '');
    el.textContent = message;
    box.appendChild(el);
    setTimeout(() => el.remove(), ms);
}

function busy(btn, on, textWhenBusy) {
    if (!btn) return;
    if (on) {
        btn.dataset.label = btn.innerHTML;
        btn.disabled = true;
        if (textWhenBusy) btn.innerHTML = textWhenBusy;
    } else {
        btn.disabled = false;
        if (btn.dataset.label) btn.innerHTML = btn.dataset.label;
    }
}

function setProgress(pct) {
    $('#progressBar').style.width = Math.max(0, Math.min(100, pct)) + '%';
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** Иконка файла. У схем подключения она зелёная, у остальных синяя. */
function fileIcon(doc) {
    const isScheme = doc && doc.doc_type === 'scheme';
    return '<span class="file-icon' + (isScheme ? ' scheme' : '') + '">'
        + '<svg viewBox="0 0 24 24" width="15" height="15">'
        + '<path d="M14 3v5h5M7 3h7l5 5v13H7z" fill="none" stroke="currentColor" '
        + 'stroke-width="1.7" stroke-linejoin="round"/></svg></span>';
}

/* ---------------- Загрузка данных ---------------- */

async function bootstrap() {
    const data = await api('/api/bootstrap');
    state.folders = data.folders || [];
    renderFolders();
    renderStats(data.stats);
}

function renderStats(stats) {
    if (!stats) return;
    $('#stPercent').textContent = stats.disk_percent + '%';
    $('#stBar').style.width = stats.disk_percent + '%';
    $('#stNote').textContent = stats.disk_used_human + ' занято из ' + stats.disk_total_human;
}

function renderFolders() {
    const box = $('#folders');
    const items = state.folders.slice();
    let html = items.map(f => `
        <a href="#" class="folder${state.folder === f.code ? ' active' : ''}" data-folder="${esc(f.code)}">
            <svg viewBox="0 0 24 24" width="18" height="18"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" fill="currentColor"/></svg>
            ${esc(f.title)}
            <span class="folder-count">${f.docs}</span>
        </a>`).join('');
    const empty = Math.max(0, 12 - items.length);
    for (let i = 0; i < empty; i++) html += '<span class="folder folder-empty"></span>';
    box.innerHTML = html;
}

function currentParams() {
    const p = Object.assign({}, state.filters);
    if (state.folder) p.folder = state.folder;
    if (state.view === 'favorite') p.favorite = '1';
    if (state.view === 'trash') p.deleted = '1';
    return p;
}

async function loadDocs() {
    const body = $('#docsBody');
    body.innerHTML = '<tr><td colspan="7" class="empty">Загрузка…</td></tr>';
    try {
        const data = await api('/api/documents', { params: currentParams() });
        state.docs = data.items || [];
        renderDocs();
        $('#docsCount').textContent = 'Всего: ' + data.total
            + (state.folder ? ' · папка ' + state.folder : '')
            + (state.view === 'trash' ? ' · корзина' : '');
    } catch (e) {
        body.innerHTML = '<tr><td colspan="7" class="empty">' + esc(e.message) + '</td></tr>';
    }
}

function renderDocs() {
    const body = $('#docsBody');
    if (!state.docs.length) {
        body.innerHTML = '<tr><td colspan="7" class="empty">Документов нет. '
            + 'Введите КОМ НОМЕР и нажмите «Загрузить характеристики».</td></tr>';
        return;
    }
    body.innerHTML = state.docs.map(d => {
        const owner = d.owner_email || d.owner_name || '—';
        const initial = (d.owner_name || d.owner_email || '?').trim().charAt(0).toUpperCase();
        return `<tr data-id="${d.id}">
            <td>
                <div class="file-cell">
                    ${fileIcon(d)}
                    <div style="min-width:0">
                        <a class="file-name" href="${esc(d.download_url)}?mode=view" target="_blank"
                           rel="noopener" title="Открыть для просмотра">${esc(d.filename)}</a>
                        <div class="file-kind">${esc(d.doc_type_title)}${d.folder_code ? ' · ' + esc(d.folder_code) : ''}</div>
                    </div>
                </div>
            </td>
            <td>${esc(d.created_human)}</td>
            <td>${esc(d.article) || '—'}</td>
            <td>${esc(d.order_no) || '—'}</td>
            <td>${esc(d.size_human)}</td>
            <td>
                <div class="owner-cell">
                    <span class="owner-avatar">${esc(initial)}</span>
                    <span class="owner-mail" title="${esc(owner)}">${esc(owner)}</span>
                </div>
            </td>
            <td>
                <div class="actions">
                    <button class="act star${d.favorite ? ' on' : ''}" data-act="star" data-id="${d.id}" title="В избранное">
                        <svg viewBox="0 0 24 24" width="17" height="17"><path d="m12 4 2.4 5 5.6.8-4 3.9 1 5.5-5-2.7-5 2.7 1-5.5-4-3.9 5.6-.8L12 4Z" fill="${d.favorite ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    </button>
                    <a class="act view" href="${esc(d.download_url)}?mode=view" target="_blank"
                       rel="noopener" title="Посмотреть без скачивания">
                        <svg viewBox="0 0 24 24" width="17" height="17"><path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                    </a>
                    <button class="act dl" data-act="download" data-id="${d.id}" title="Скачать">
                        <svg viewBox="0 0 24 24" width="17" height="17"><path d="M12 4v10m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button class="act kebab" data-act="menu" data-id="${d.id}" title="Действия">
                        <svg viewBox="0 0 24 24" width="17" height="17"><circle cx="12" cy="5" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="19" r="1.7" fill="currentColor"/></svg>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

/* ---------------- Операции AUMA ---------------- */

async function fetchCharacteristics() {
    const order = $('#orderInput').value.trim();
    if (!/^\d{4,12}$/.test(order)) {
        toast('Введите КОМ НОМЕР — 4–12 цифр', 'error');
        $('#orderInput').focus();
        return;
    }
    const btn = $('#opChars');
    busy(btn, true, 'Загрузка…');
    setProgress(15);
    $('#orderHint').textContent = 'Запрашиваю характеристики по заказу ' + order + '…';
    try {
        setProgress(45);
        const res = await api('/api/fetch/characteristics', {
            method: 'POST',
            body: { order, format: state.fmt }
        });
        setProgress(100);
        const f = res.item;
        toast('Загружено: ' + f.filename + (f.folder_code ? ' → папка ' + f.folder_code : ''), 'ok');
        $('#orderHint').textContent = 'Готово: ' + f.filename;
        await refreshAll();
    } catch (e) {
        toast(e.message, 'error', 8000);
        $('#orderHint').textContent = '';
        setProgress(0);
    } finally {
        busy(btn, false);
        setTimeout(() => setProgress(0), 1200);
    }
}

async function fetchScheme() {
    const order = $('#orderInput').value.trim();
    if (!/^\d{4,12}$/.test(order)) {
        toast('Введите КОМ НОМЕР — 4–12 цифр', 'error');
        $('#orderInput').focus();
        return;
    }
    const btn = $('#opScheme');
    busy(btn, true, 'Загрузка…');
    setProgress(15);
    $('#orderHint').textContent = 'Запрашиваю схему подключения по заказу ' + order + '…';
    try {
        setProgress(45);
        const res = await api('/api/fetch/scheme', { method: 'POST', body: { order } });
        setProgress(100);
        const f = res.item;
        toast('Загружено: ' + f.filename + (res.article ? ' · ' + res.article : ''), 'ok');
        $('#orderHint').textContent = 'Готово: ' + f.filename;
        await refreshAll();
    } catch (e) {
        toast(e.message, 'error', 8000);
        $('#orderHint').textContent = '';
        setProgress(0);
    } finally {
        busy(btn, false);
        setTimeout(() => setProgress(0), 1200);
    }
}

async function refreshAll() {
    await loadDocs();
    const data = await api('/api/bootstrap');
    state.folders = data.folders || [];
    renderFolders();
    renderStats(data.stats);
}

/* ---------------- Действия в строке ---------------- */

async function toggleStar(id) {
    const doc = state.docs.find(d => d.id === id);
    if (!doc) return;
    try {
        const res = await api('/api/documents/' + id + '/favorite', {
            method: 'POST', body: { value: !doc.favorite }
        });
        doc.favorite = res.favorite;
        renderDocs();
    } catch (e) {
        toast(e.message, 'error');
    }
}

function openRowMenu(id, anchor) {
    const menu = $('#rowMenu');
    // id храним прямо на элементе меню: state может обнулиться от постороннего события
    menu.dataset.docId = String(id);
    state.menuDocId = id;
    menu.hidden = false;
    const r = anchor.getBoundingClientRect();
    const w = menu.offsetWidth || 190;
    const h = menu.offsetHeight || 90;
    let left = r.right - w;
    let top = r.bottom + 6;
    if (left < 8) left = 8;
    if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
}

function closeRowMenu() {
    $('#rowMenu').hidden = true;
    state.menuDocId = null;
}

/** Своё окно подтверждения вместо window.confirm. */
function confirmDialog(title, text, okLabel) {
    return new Promise(resolve => {
        const modal = $('#confirmModal');
        $('#confirmTitle').textContent = title;
        $('#confirmText').textContent = text;
        $('#confirmOk').textContent = okLabel || 'Удалить';
        modal.hidden = false;
        $('#confirmOk').focus();

        const done = val => {
            modal.hidden = true;
            $('#confirmOk').removeEventListener('click', onOk);
            $('#confirmCancel').removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdrop);
            document.removeEventListener('keydown', onKey);
            resolve(val);
        };
        const onOk = () => done(true);
        const onCancel = () => done(false);
        const onBackdrop = e => { if (e.target === modal) done(false); };
        const onKey = e => { if (e.key === 'Escape') done(false); };

        $('#confirmOk').addEventListener('click', onOk);
        $('#confirmCancel').addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKey);
    });
}

/**
 * Скачивание через fetch, а не обычной ссылкой: так видно,
 * если сервер ответил ошибкой, вместо молчаливой неудачи.
 */
async function downloadDoc(id) {
    const doc = state.docs.find(d => d.id === id);
    const name = doc ? doc.filename : 'file';
    toast('Скачиваю ' + name + '…');
    try {
        const res = await fetch(url('/api/documents/' + id + '/download'), {
            credentials: 'same-origin'
        });
        if (!res.ok) {
            let msg = 'Не удалось скачать файл (ошибка ' + res.status + ')';
            try {
                const j = await res.json();
                if (j && j.error) msg = j.error;
            } catch (e) { /* ответ не JSON */ }
            throw new Error(msg);
        }
        const blob = await res.blob();
        const href = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = href;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(href), 15000);
        toast('Файл скачан: ' + name, 'ok');
    } catch (e) {
        toast(e.message, 'error', 8000);
    }
}

async function deleteDoc(id) {
    const doc = state.docs.find(d => d.id === id);
    const name = doc ? doc.filename : ('#' + id);
    const ok = await confirmDialog('Удалить файл?',
        '«' + name + '» будет удалён с сайта и с диска. Отменить это нельзя.', 'Удалить');
    if (!ok) return;
    try {
        await api('/api/documents/' + id, { method: 'DELETE' });
        toast('Файл удалён: ' + name, 'ok');
        await refreshAll();
    } catch (e) {
        toast(e.message, 'error');
    }
}

function openMailModal(id) {
    const doc = state.docs.find(d => d.id === id);
    state.mailDocId = id;
    $('#mailDocName').textContent = doc ? doc.filename : '';
    $('#mailTo').value = (window.AUMA && window.AUMA.user && window.AUMA.user.email) || '';
    $('#mailNote').textContent = '';
    $('#mailModal').hidden = false;
    setTimeout(() => $('#mailTo').focus(), 30);
}

async function sendMail() {
    const to = $('#mailTo').value.trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) {
        $('#mailNote').textContent = 'Укажите корректный адрес';
        return;
    }
    busy($('#mailSend'), true, 'Отправка…');
    try {
        const res = await api('/api/documents/' + state.mailDocId + '/email', {
            method: 'POST', body: { to }
        });
        $('#mailModal').hidden = true;
        toast(res.message || 'Готово', 'ok', 7000);
    } catch (e) {
        $('#mailNote').textContent = e.message;
    } finally {
        busy($('#mailSend'), false);
    }
}

/* ---------------- Навигация и фильтры ---------------- */

function setView(view) {
    state.view = view;
    state.folder = '';
    $$('#sideNav .nav-item').forEach(a => a.classList.remove('active'));
    const el = $('#sideNav .nav-item[data-view="' + view + '"]');
    if (el) el.classList.add('active');
    if (view === 'settings' || view === 'cloud') {
        toast('Раздел в разработке');
    }
    loadDocs();
}

function setFolder(code) {
    state.folder = (state.folder === code) ? '' : code;
    state.view = 'dashboard';
    $$('#sideNav .nav-item').forEach(a => a.classList.remove('active'));
    const el = $('#sideNav .nav-item[data-folder="' + state.folder + '"]');
    if (el) el.classList.add('active');
    renderFolders();
    loadDocs();
}

function collectFilters() {
    const f = {};
    $$('.f-input').forEach(inp => {
        const v = inp.value.trim();
        if (v !== '') f[inp.dataset.filter] = v;
    });
    state.filters = f;
}

let filterTimer = null;
function onFilterInput() {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(() => {
        collectFilters();
        loadDocs();
    }, 350);
}

/* ---------------- Инициализация ---------------- */

function init() {
    // Операции
    $('#opChars').addEventListener('click', fetchCharacteristics);
    $('#opScheme').addEventListener('click', fetchScheme);
    $('#opPassport').addEventListener('click', () => toast('Создание паспорта пока не реализовано'));

    // Формат
    $$('#fmtTabs .fmt').forEach(b => b.addEventListener('click', () => {
        $$('#fmtTabs .fmt').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        state.fmt = b.dataset.fmt;
    }));

    // Заказ
    const orderInput = $('#orderInput');
    orderInput.addEventListener('keydown', e => { if (e.key === 'Enter') fetchCharacteristics(); });
    orderInput.addEventListener('input', () => { state.order = orderInput.value.trim(); });

    // Навигация
    $('#sideNav').addEventListener('click', e => {
        const a = e.target.closest('.nav-item');
        if (!a) return;
        e.preventDefault();
        if (a.dataset.folder) setFolder(a.dataset.folder);
        else if (a.dataset.view) setView(a.dataset.view);
    });

    // Папки
    $('#folders').addEventListener('click', e => {
        const a = e.target.closest('.folder');
        if (!a || !a.dataset.folder) return;
        e.preventDefault();
        setFolder(a.dataset.folder);
    });

    // Фильтры
    $$('.f-input').forEach(inp => {
        inp.addEventListener('input', onFilterInput);
        inp.addEventListener('change', onFilterInput);
    });
    $('#clearFilters').addEventListener('click', () => {
        $$('.f-input').forEach(i => { i.value = ''; });
        collectFilters();
        loadDocs();
    });
    $('#resetFilters').addEventListener('click', () => {
        $$('.f-input').forEach(i => { i.value = ''; });
        collectFilters();
        state.folder = '';
        state.view = 'dashboard';
        $$('#sideNav .nav-item').forEach(a => a.classList.remove('active'));
        const el = $('#sideNav .nav-item[data-view="dashboard"]');
        if (el) el.classList.add('active');
        renderFolders();
        loadDocs();
    });
    $('#refreshBtn').addEventListener('click', () => refreshAll());

    // Поиск в шапке — ищем по колонке File
    const gs = $('#globalSearch');
    let gsTimer = null;
    gs.addEventListener('input', () => {
        clearTimeout(gsTimer);
        gsTimer = setTimeout(() => {
            const v = gs.value.trim();
            const fileInput = $('.f-input[data-filter="file"]');
            if (fileInput) fileInput.value = v;
            collectFilters();
            loadDocs();
        }, 350);
    });

    // Действия в таблице
    $('#docsBody').addEventListener('click', e => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const id = parseInt(btn.dataset.id, 10);
        if (btn.dataset.act === 'star') { e.preventDefault(); toggleStar(id); }
        if (btn.dataset.act === 'menu') { e.preventDefault(); openRowMenu(id, btn); }
        if (btn.dataset.act === 'download') { e.preventDefault(); downloadDoc(id); }
    });

    // Меню строки
    $('#rowMenu').addEventListener('click', e => {
        const b = e.target.closest('button[data-act]');
        if (!b) return;
        e.preventDefault();
        const id = parseInt($('#rowMenu').dataset.docId || '', 10);
        const act = b.dataset.act;
        closeRowMenu();
        if (!Number.isFinite(id)) return;
        if (act === 'delete') deleteDoc(id);
        if (act === 'email') openMailModal(id);
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('#rowMenu') && !e.target.closest('[data-act="menu"]')) closeRowMenu();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeRowMenu(); $('#mailModal').hidden = true; }
    });

    // Модальное окно
    $('#mailCancel').addEventListener('click', () => { $('#mailModal').hidden = true; });
    $('#mailSend').addEventListener('click', sendMail);
    $('#mailModal').addEventListener('click', e => {
        if (e.target === $('#mailModal')) $('#mailModal').hidden = true;
    });
    $('#mailTo').addEventListener('keydown', e => { if (e.key === 'Enter') sendMail(); });

    // Заглушки
    $('#inviteBtn').addEventListener('click', () => toast('Приглашения появятся позже'));
    $('#bellBtn').addEventListener('click', () => toast('Уведомлений нет'));
    $('#uploadFileBtn').addEventListener('click', () => toast('Ручная загрузка файлов появится позже'));

    // Старт
    bootstrap().then(loadDocs).catch(e => toast(e.message, 'error'));
}

document.addEventListener('DOMContentLoaded', init);
