<?php
/**
 * Просмотрщик документа.
 *
 * HTML-документы не отдаются как самостоятельная страница: некоторые клиенты
 * (встроенные браузеры приложений) отказываются показывать text/html с адреса
 * вида /api/.../download. Поэтому документ встраивается внутрь нашей страницы
 * через iframe srcdoc — тогда верхним документом остаётся обычная страница сайта.
 */
$doc = $doc ?? [];
$isHtml = $isHtml ?? false;
$embed = $embed ?? '';
$downloadUrl = base_path('/api/documents/' . (int) $doc['id'] . '/download');
$rawUrl = $downloadUrl . '?mode=view';
$backUrl = base_path('/');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= e($doc['filename']) ?> — <?= e((string) config('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body class="viewer-body">

<header class="viewer-bar">
    <a class="viewer-back" href="<?= e($backUrl) ?>" title="Назад">
        <svg viewBox="0 0 24 24" width="18" height="18"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="viewer-icon<?= $isHtml ? '' : ' pdf' ?>">
        <svg viewBox="0 0 24 24" width="15" height="15"><path d="M14 3v5h5M7 3h7l5 5v13H7z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
    </span>
    <div class="viewer-info">
        <div class="viewer-name" title="<?= e($doc['filename']) ?>"><?= e($doc['filename']) ?></div>
        <div class="viewer-meta">
            <?= e($doc['doc_type'] === 'scheme' ? 'Схема подключения' : ($doc['doc_type'] === 'characteristics' ? 'Характеристики' : 'Документ')) ?>
            <?php if (!empty($doc['order_no'])): ?> · заказ <?= e((string) $doc['order_no']) ?><?php endif; ?>
            <?php if (!empty($doc['article'])): ?> · <?= e((string) $doc['article']) ?><?php endif; ?>
            · <?= e(human_size((int) $doc['size'])) ?>
        </div>
    </div>
    <a class="btn btn-ghost viewer-dl" href="<?= e($downloadUrl) ?>">
        <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 4v10m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Скачать
    </a>
</header>

<?php if ($isHtml): ?>
    <?php /* Разметка уже очищена на сервере (sanitize_html). Атрибут sandbox
             здесь не годится: с ним браузер вообще не создаёт фрейм. */ ?>
    <iframe class="viewer-frame" srcdoc="<?= e($embed) ?>" title="<?= e($doc['filename']) ?>"></iframe>
<?php else: ?>
    <iframe class="viewer-frame" src="<?= e($rawUrl) ?>" title="<?= e($doc['filename']) ?>"></iframe>
<?php endif; ?>

</body>
</html>
