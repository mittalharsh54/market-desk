<?php
/* =====================================================================
   Market Desk — API (api.php)
   ---------------------------------------------------------------------
   index.html calls api.php?action=... ; everything answers JSON.
     auth_status   is the app set up, and is this browser signed in?
     login         { password }
     logout
     mkt_*         the market actions in market.php (signed-in only)
   ===================================================================== */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/market.php';

/* a PHP fatal on shared hosting returns an empty body; say which file and line instead */
register_shutdown_function(function () {
  $e = error_get_last();
  if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
  if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
  echo json_encode(['error' => 'PHP fatal: ' . $e['message'] . ' — ' . basename($e['file']) . ':' . $e['line']]);
});

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

md_session_start();
$action = (string) ($_GET['action'] ?? '');

if ($action === 'auth_status') {
  echo json_encode(['ok' => true, 'configured' => md_configured() || md_config()['dev'], 'signed_in' => md_signed_in(), 'ai' => md_config()['anthropic_key'] !== '']);
  exit;
}
if ($action === 'login') {
  if (!md_configured()) fail(503, 'Set a password in config.php first (copy config.sample.php to config.php).');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only.');
  $b = json_decode(file_get_contents('php://input'), true) ?: [];
  md_login($b['password'] ?? '');
  echo json_encode(['ok' => true]);
  exit;
}
if ($action === 'logout') {
  $_SESSION = []; session_destroy();
  echo json_encode(['ok' => true]);
  exit;
}

if (!md_signed_in()) fail(401, 'Please sign in.');
session_write_close(); // market calls can take a while; don't hold the session lock
if (strpos($action, 'mkt_') !== 0) fail(400, 'Unknown action.');
mkt_dispatch($action);
