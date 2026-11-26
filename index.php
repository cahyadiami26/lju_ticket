<?php
/** Entry point — redirect ke dashboard (session sudah dicek di session.php). */
require_once __DIR__ . '/includes/session.php';
header('Location: dashboard.php');
exit;
