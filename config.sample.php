<?php
/* Market Desk settings.
   Copy this file to config.php (same folder) and fill it in. config.php is
   never committed to git — it holds your password and API key. */

/* The password for signing in. Pick a long one. */
$APP_PASSWORD = 'change-me-to-something-long';

/* Or, instead of a plain password, a hash of it (safer if others can read
   files on your server). Make one with:
     php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"
   and paste it here; it wins over $APP_PASSWORD when set. */
// $APP_PASSWORD_HASH = '$2y$10$...';

/* Optional: an Anthropic API key for the ✨ AI research notes
   (console.anthropic.com → API keys). Everything else works without it;
   the AI buttons just explain how to add one. */
$ANTHROPIC_API_KEY = '';
$CLAUDE_MODEL = 'claude-sonnet-5';

/* Optional: where the app keeps its cache and your India macro inputs.
   Default is the data/ folder next to this file (web access to it is blocked
   by .htaccess). Point it outside public_html if your host allows. */
// $DATA_DIR = '/home/you/market-desk-data';
