<?php
/**
 * Layout header + topbar.
 * Dipakai setelah session.php (yang sudah memuat config).
 */
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/auth.php';

$user = current_user();
$currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
$currentRel = ltrim(preg_replace('#^' . preg_quote(app_base_url(), '#') . '#', '', $currentScript), '/');
$currentPage = basename($currentRel);

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(LOGO_URL) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="main">
        <header class="topbar">
            <button class="btn-icon sidebar-toggle" id="sidebarToggle" aria-label="Menu">☰</button>
            <div class="topbar-title"><?= e($pageTitle ?? 'Dashboard') ?></div>
            <div class="topbar-user">
                <div class="topbar-user-info">
                    <div class="topbar-user-name"><?= e($user['name']) ?></div>
                    <div class="topbar-user-meta"><?= e($user['position'] ?: $user['role']) ?><?= !empty($user['division']) ? ' · ' . e($user['division']) : '' ?></div>
                </div>
                <?= avatar_html($user, 36) ?>
                <a href="<?= e(url('profile.php')) ?>" class="btn btn-sm btn-ghost">Profil</a>
                <a href="<?= e(url('logout.php')) ?>" class="btn btn-sm btn-ghost">Keluar</a>
            </div>
        </header>
        <main class="content">