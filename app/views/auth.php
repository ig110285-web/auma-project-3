<?php
/** Вход и регистрация. */
$isRegister = $registerMode ?? false;
$appName = (string) config('app.name', 'AUMA Documentation');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($isRegister ? 'Регистрация' : 'Вход') ?> — <?= e($appName) ?></title>
<link rel="stylesheet" href="<?= e(base_path('/assets/app.css')) ?>">
</head>
<body class="auth-body">

<div class="auth-card">
    <div class="auth-brand">
        <span class="brand-dot"></span>
        <span class="brand-name"><?= e($appName) ?></span>
    </div>

    <h1 class="auth-title"><?= $isRegister ? 'Регистрация' : 'Вход в систему' ?></h1>
    <p class="auth-sub">
        <?= $isRegister
            ? 'Создайте аккаунт — email станет владельцем загруженных документов.'
            : 'Войдите, чтобы работать с документацией по номеру заказа.' ?>
    </p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
        <?php if ($isRegister): ?>
        <label class="field">
            <span class="field-label">Имя</span>
            <input class="input" type="text" name="name" autocomplete="name"
                   value="<?= e($_POST['name'] ?? '') ?>" placeholder="Как к вам обращаться">
        </label>
        <?php endif; ?>

        <label class="field">
            <span class="field-label">Email</span>
            <input class="input" type="email" name="email" required autocomplete="email"
                   value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com">
        </label>

        <label class="field">
            <span class="field-label">Пароль</span>
            <input class="input" type="password" name="password" required
                   autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>"
                   placeholder="Минимум 6 символов">
        </label>

        <button class="btn btn-primary btn-block" type="submit">
            <?= $isRegister ? 'Зарегистрироваться' : 'Войти' ?>
        </button>
    </form>

    <div class="auth-switch">
        <?php if ($isRegister): ?>
            Уже есть аккаунт? <a href="<?= e(base_path('/login')) ?>">Войти</a>
        <?php else: ?>
            Нет аккаунта? <a href="<?= e(base_path('/register')) ?>">Зарегистрироваться</a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
