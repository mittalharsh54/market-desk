<?php
/* =====================================================================
   Market Desk — app core (lib.php)
   ---------------------------------------------------------------------
   Config, file storage, the password login and the Claude call. No
   database: everything the app keeps lives as JSON files in data/.
   ===================================================================== */

/* ---------- config ---------- */
function md_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $cfg = ['password' => '', 'password_hash' => '', 'anthropic_key' => '', 'claude_model' => 'claude-sonnet-5', 'data_dir' => __DIR__ . '/data', 'dev' => false, 'telegram_token' => ''];
  if (defined('MD_DEV')) { $cfg['dev'] = true; $cfg['data_dir'] = sys_get_temp_dir() . '/market-desk-dev'; $cfg['password'] = 'dev'; $cfg['anthropic_key'] = 'dev'; $cfg['telegram_token'] = 'dev'; return $cfg; }
  $f = __DIR__ . '/config.php';
  if (is_file($f)) {
    $APP_PASSWORD = $APP_PASSWORD_HASH = $ANTHROPIC_API_KEY = $CLAUDE_MODEL = $DATA_DIR = $TELEGRAM_BOT_TOKEN = null;
    include $f;
    if ($TELEGRAM_BOT_TOKEN !== null) $cfg['telegram_token'] = trim((string) $TELEGRAM_BOT_TOKEN);
    if ($APP_PASSWORD !== null) $cfg['password'] = (string) $APP_PASSWORD;
    if ($APP_PASSWORD_HASH !== null) $cfg['password_hash'] = (string) $APP_PASSWORD_HASH;
    if ($ANTHROPIC_API_KEY !== null) $cfg['anthropic_key'] = trim((string) $ANTHROPIC_API_KEY);
    if (!empty($CLAUDE_MODEL)) $cfg['claude_model'] = (string) $CLAUDE_MODEL;
    if (!empty($DATA_DIR)) $cfg['data_dir'] = rtrim((string) $DATA_DIR, '/');
  }
  if ($cfg['anthropic_key'] === '' && getenv('ANTHROPIC_API_KEY')) $cfg['anthropic_key'] = getenv('ANTHROPIC_API_KEY');
  return $cfg;
}
function md_configured() { $c = md_config(); return $c['password'] !== '' || $c['password_hash'] !== ''; }

/* ---------- JSON responses ---------- */
function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}

/* ---------- file storage (data/) ---------- */
function md_path($name) {
  $dir = md_config()['data_dir'];
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
    /* belt and braces: deny web access even if the host ignores the root .htaccess */
    @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    @file_put_contents($dir . '/index.html', '');
  }
  return $dir . '/' . $name;
}
function md_store_get($key) {
  $f = md_path(md_store_file($key));
  if (!is_file($f)) return null;
  $v = @file_get_contents($f);
  return $v === false ? null : $v;
}
function md_store_set($key, $value) {
  $f = md_path(md_store_file($key));
  if ($value === '' || $value === null) { @unlink($f); return; }
  $tmp = $f . '.' . getmypid() . '.tmp';
  if (@file_put_contents($tmp, $value, LOCK_EX) !== false) @rename($tmp, $f);
  /* now and then, clear cache files nobody has refreshed in 3 days */
  if (mt_rand(1, 200) === 1) foreach ((array) glob(md_path('c_*.json')) as $old) if (@filemtime($old) < time() - 3 * 86400) @unlink($old);
}
/* named keys are kept readable; cache keys are hashed */
function md_store_file($key) { return preg_match('/^[a-z_]{1,40}$/', $key) ? $key . '.json' : 'c_' . md5($key) . '.json'; }

/* ---------- login ---------- */
function md_session_start() {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_name('mdsess');
  session_set_cookie_params(['lifetime' => 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
  ini_set('session.gc_maxlifetime', (string) (30 * 86400));
  session_start();
}
function md_signed_in() { return md_config()['dev'] || !empty($_SESSION['md_ok']); }
function md_login($password) {
  $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
  $fails = json_decode((string) md_store_get('login_fails'), true) ?: [];
  $mine = $fails[$ip] ?? ['n' => 0, 'at' => 0];
  if ($mine['n'] >= 8 && time() - $mine['at'] < 900) fail(429, 'Too many wrong passwords. Try again in 15 minutes.');
  $c = md_config(); $ok = false;
  if ($c['password_hash'] !== '') $ok = password_verify((string) $password, $c['password_hash']);
  elseif ($c['password'] !== '') $ok = hash_equals($c['password'], (string) $password);
  if (!$ok) {
    $fails[$ip] = ['n' => ($mine['n'] ?? 0) + 1, 'at' => time()];
    md_store_set('login_fails', json_encode($fails));
    usleep(400000);
    fail(401, 'Wrong password.');
  }
  unset($fails[$ip]); md_store_set('login_fails', json_encode($fails) ?: '');
  session_regenerate_id(true);
  $_SESSION['md_ok'] = 1;
}

/* ---------- Claude ---------- */
function md_claude($system, $user, $maxTokens = 3000) {
  $c = md_config();
  if (isset($GLOBALS['MD_CLAUDE_MOCK']) && is_callable($GLOBALS['MD_CLAUDE_MOCK'])) return call_user_func($GLOBALS['MD_CLAUDE_MOCK'], $system, $user);
  if ($c['anthropic_key'] === '') throw new Exception('Add your Anthropic API key to config.php ($ANTHROPIC_API_KEY) to get written notes.');
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 170,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $c['anthropic_key'], 'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS => json_encode(['model' => $c['claude_model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]])]);
  $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
  if ($res === false) throw new Exception('Could not reach Claude: ' . $err);
  $j = json_decode($res, true);
  if ($code !== 200) throw new Exception('Claude ' . $code . ': ' . ($j['error']['message'] ?? substr($res, 0, 200)));
  $text = ''; foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
  return ['note' => $text, 'model' => $j['model'] ?? $c['claude_model']];
}
