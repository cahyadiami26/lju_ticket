<?php
/** Entry point — redirect ke dashboard (proteksi session ada di session.php). */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url.php';
header('Location: ' . url('dashboard.php'));
exit;
