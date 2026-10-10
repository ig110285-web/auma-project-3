<?php
/** Дашборд: разметка по макету ref-3. Данные подтягивает app.js. */
$appName = (string) config('app.name', 'AUMA Documentation');
$u = $user;
$initial = mb_strtoupper(mb_substr($u['name'] !== '' ? $u['name'] : $u['email'], 0, 1));
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= e($appName) ?></title>
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body>

<div class="app">

    <!-- ================= Сайдбар ================= -->
    <aside class="sidebar">
        <div class="side-brand">
            <span class="brand-dot"></span>
            <span class="brand-name"><?= e($appName) ?></span>
        </div>

        <div class="side-user">
            <span class="avatar"><?= e($initial) ?></span>
            <span class="side-user-info">
                <span class="side-user-name"><?= e($u['name'] ?: $u['email']) ?></span>
                <span class="side-user-mail"><?= e($u['email']) ?></span>
            </span>
            <svg class="chev" viewBox="0 0 24 24" width="16" height="16"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </div>

        <div class="side-label">MAIN MENU</div>

        <nav class="side-nav" id="sideNav">
            <a href="#" class="nav-item active" data-view="dashboard">
                <svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 13h7V4H4v9Zm0 7h7v-5H4v5Zm9 0h7v-9h-7v9Zm0-16v5h7V4h-7Z" fill="currentColor"/></svg>
                Dashboard
            </a>
            <?php
            // Группы и папки берутся из app/catalog.php — добавление новой
            // группы не требует правок в этом шаблоне.
            $groupIcons = [
                'drive'    => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 5.5A3.5 3.5 0 1 1 12 15.5 3.5 3.5 0 0 1 12 8.5Z" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 3v3.5M12 17.5V21M3 12h3.5M17.5 12H21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
                'controls' => '<rect x="4" y="5" width="16" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8 9h8M8 12.5h5M8 16h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
                // редуктор — шестерня: внутренний круг и зубцы пунктиром
                'gearbox'  => '<circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="7.4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-dasharray="2.4 2.6"/>',
            ];
            ?>
            <?php foreach (catalog() as $group): ?>
            <div class="nav-group">
                <button type="button" class="nav-item nav-toggle" data-group="<?= e($group['code']) ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18"><?= $groupIcons[$group['code']] ?? $groupIcons['drive'] ?></svg>
                    <?= e($group['title']) ?>
                    <svg class="chev" viewBox="0 0 24 24" width="14" height="14"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <div class="nav-sub">
                    <?php foreach ($group['folders'] as $folder): ?>
                        <a href="#" class="nav-item nav-sub-item" data-folder="<?= e($folder['code']) ?>"><?= e($folder['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <a href="#" class="nav-item" data-view="trash">
                <svg viewBox="0 0 24 24" width="18" height="18"><path d="M5 7h14M10 7V5h4v2m-7 0 1 12h8l1-12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                Trash
            </a>
            <a href="#" class="nav-item" data-view="settings">
                <svg viewBox="0 0 24 24" width="18" height="18"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6l1.4 1.4m10 10 1.4 1.4m0-12.8-1.4 1.4m-10 10-1.4 1.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                Settings
            </a>
        </nav>

        <div class="storage-card">
            <div class="storage-title">Need More Space?</div>
            <div class="storage-text">Хранилище документов на сервере.</div>
            <div class="storage-row"><span>Available Storage</span><b id="stPercent">—</b></div>
            <div class="storage-bar"><i id="stBar" style="width:0%"></i></div>
            <div class="storage-note" id="stNote">—</div>
        </div>
    </aside>

    <!-- ================= Контент ================= -->
    <main class="main">
        <header class="topbar">
            <div class="search">
                <svg viewBox="0 0 24 24" width="18" height="18"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16.5 16.5 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input type="search" id="globalSearch" placeholder="Search hear...">
                <span class="kbd">⌘ F</span>
            </div>
            <div class="topbar-actions">
                <button class="btn btn-ghost" id="inviteBtn" type="button">
                    <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="10" cy="8" r="3.2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M4.5 19a5.5 5.5 0 0 1 11 0M18 8v6M15 11h6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    Invite
                </button>
                <button class="icon-btn" id="bellBtn" type="button" title="Уведомления">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 4a5 5 0 0 0-5 5v3l-1.5 3h13L17 12V9a5 5 0 0 0-5-5Zm-2 13a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                </button>
                <form method="post" action="<?= e(base_path('/logout')) ?>" class="logout-form">
                    <button class="icon-btn" type="submit" title="Выйти">
                        <svg viewBox="0 0 24 24" width="18" height="18"><path d="M15 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3M10 8l-4 4 4 4M6 12h9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <h1>Welcome Back</h1>
                    <p class="page-sub">Streamlined file management for seamless workflows.</p>
                </div>
                <button class="btn btn-primary" id="uploadFileBtn" type="button">
                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 16V5m0 0L8 9m4-4 4 4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Upload File
                </button>
            </div>

            <!-- Заказ + формат -->
            <section class="order-block">
                <label class="order-label" for="orderInput">КОМ НОМЕР</label>
                <div class="order-row">
                    <input class="order-input" id="orderInput" type="text" inputmode="numeric"
                           placeholder="23080383" autocomplete="off">
                    <div class="fmt-tabs" id="fmtTabs">
                        <button type="button" class="fmt" data-fmt="eng">eng</button>
                        <button type="button" class="fmt" data-fmt="de">de</button>
                        <button type="button" class="fmt" data-fmt="web">web</button>
                        <button type="button" class="fmt active" data-fmt="pdf">pdf</button>
                    </div>
                </div>
                <div class="order-hint" id="orderHint"></div>
            </section>

            <!-- Операции -->
            <section class="block">
                <div class="block-head">
                    <h2>Операции</h2>
                    <a href="#" class="link" data-view="dashboard">View All</a>
                </div>
                <div class="ops">
                    <button class="op" id="opChars" type="button">
                        <span class="op-icon">
                            <svg viewBox="0 0 24 24" width="26" height="26"><path d="M12 4v10m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="op-title">Загрузить характеристики</span>
                    </button>
                    <button class="op" id="opScheme" type="button">
                        <span class="op-icon">
                            <svg viewBox="0 0 24 24" width="26" height="26"><path d="M12 4v10m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="op-title">Загрузить схему пдкл.</span>
                    </button>
                    <button class="op" id="opPassport" type="button">
                        <span class="op-icon">
                            <svg viewBox="0 0 24 24" width="26" height="26">
                                <path d="M6 3h8l4 4v14H6z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                <path d="M14 3v4h4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                <path d="M9 11h6M9 14h3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                <circle cx="15" cy="17.5" r="2.3" fill="none" stroke="currentColor" stroke-width="1.4"/>
                            </svg>
                        </span>
                        <span class="op-title">Создать паспорт</span>
                    </button>
                    <span class="op op-empty"></span>
                    <span class="op op-empty"></span>
                    <span class="op op-empty"></span>
                </div>
            </section>

            <div class="progress"><i id="progressBar" style="width:0%"></i></div>

            <!-- Папки -->
            <section class="block">
                <div class="block-head">
                    <h2>Folders</h2>
                    <a href="#" class="link" data-view="dashboard">View All</a>
                </div>
                <div class="folders" id="folders"></div>
            </section>

            <!-- Документы -->
            <section class="block">
                <div class="block-head">
                    <h2>Документы</h2>
                    <div class="view-tools">
                        <button class="tool" id="resetFilters" type="button" title="Сбросить все фильтры">
                            <svg viewBox="0 0 24 24" width="15" height="15"><path d="M3 5h18l-7 8v6l-4-2v-4L3 5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                            Сбросить
                        </button>
                        <button class="tool" id="toggleView" type="button">
                            <svg viewBox="0 0 24 24" width="15" height="15"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <span id="viewLabel">List</span>
                        </button>
                        <button class="tool" id="refreshBtn" type="button">
                            <svg viewBox="0 0 24 24" width="15" height="15"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v4h-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            Обновить
                        </button>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table" id="docsTable">
                        <thead>
                            <tr class="head-row">
                                <th class="col-file">File</th>
                                <th class="col-article">Article</th>
                                <th class="col-order">Order</th>
                                <th class="col-size">File Size</th>
                                <th class="col-date">Data Uploaded</th>
                                <th class="col-owner">File Owner</th>
                                <th class="col-action">Action</th>
                            </tr>
                            <tr class="filter-row">
                                <th><input class="f-input" data-filter="file" placeholder="фильтр"></th>
                                <th><input class="f-input" data-filter="article" placeholder="фильтр"></th>
                                <th><input class="f-input" data-filter="order" placeholder="фильтр"></th>
                                <th></th>
                                <th>
                                    <div class="f-dates">
                                        <input class="f-input" type="date" data-filter="date_from" title="с">
                                        <input class="f-input" type="date" data-filter="date_to" title="по">
                                    </div>
                                </th>
                                <th><input class="f-input" data-filter="owner" placeholder="фильтр"></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="docsBody">
                            <tr><td colspan="7" class="empty">Загрузка…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="table-foot">
                    <span id="docsCount">—</span>
                </div>
            </section>
        </div>
    </main>
</div>

<!-- Меню действий в строке -->
<div class="menu" id="rowMenu" hidden>
    <button type="button" data-act="email">Отправить на почту</button>
    <button type="button" data-act="delete" class="danger">Удалить файл</button>
</div>

<!-- Модальное окно отправки на почту -->
<div class="modal-backdrop" id="mailModal" hidden>
    <div class="modal">
        <h3>Отправить на почту</h3>
        <p class="modal-sub" id="mailDocName">—</p>
        <label class="field">
            <span class="field-label">Адрес получателя</span>
            <input class="input" type="email" id="mailTo" placeholder="someone@example.com">
        </label>
        <div class="modal-note" id="mailNote"></div>
        <div class="modal-actions">
            <button class="btn btn-ghost" type="button" id="mailCancel">Отмена</button>
            <button class="btn btn-primary" type="button" id="mailSend">Отправить</button>
        </div>
    </div>
</div>

<!-- Подтверждение удаления: своё окно вместо window.confirm,
     которое браузер может блокировать после нескольких показов -->
<div class="modal-backdrop" id="confirmModal" hidden>
    <div class="modal">
        <h3 id="confirmTitle">Удалить файл?</h3>
        <p class="modal-sub" id="confirmText">—</p>
        <div class="modal-actions">
            <button class="btn btn-ghost" type="button" id="confirmCancel">Отмена</button>
            <button class="btn btn-danger" type="button" id="confirmOk">Удалить</button>
        </div>
    </div>
</div>

<!-- Универсальное уведомление -->
<div class="toasts" id="toasts"></div>

<script>
window.AUMA = {
    base: <?= json_encode(base_path('/'), JSON_UNESCAPED_SLASHES) ?>,
    user: <?= json_encode(['email' => $u['email'], 'name' => $u['name']], JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= e(asset('/assets/app.js')) ?>"></script>
</body>
</html>
