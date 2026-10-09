<?php
// ============================================================
//  Vivistays Leads – settings TEMPLATE.
//  On the server, copy this file to config.local.php and edit that copy.
//  config.local.php is never in GitHub and is never overwritten by a deploy.
// ============================================================

// 1. Who can sign in. One line per person: 'Name' => 'password'.
//    Use long passwords (12+ characters). Change these before going live.
$USERS = [
    'Alpha' => 'CHANGE-THIS-PASSWORD-1',
    'Val'   => 'CHANGE-THIS-PASSWORD-2',
];

// 2. Anthropic API key, for the "Fill in with AI" and "Rewrite with AI" buttons.
//    Get one at https://console.anthropic.com (Settings > API keys).
//    Leave it as '' to run without AI; everything else still works.
$ANTHROPIC_API_KEY = '';

// 3. AI models (you can leave these alone).
$MODEL_QUICK   = 'claude-haiku-5-5';   // reading pasted LinkedIn and event pages
$MODEL_DEFAULT = 'claude-sonnet-5-5';  // reading Facebook posts, writing messages
