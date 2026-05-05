<?php
// Re-register the Telegram webhook. Run once from the server (browser or CLI)
// after deploying. Not deployed by deploy.sh — uploaded manually then deleted.

require_once __DIR__ . '/config.php';

$url = 'https://phoenixsoftware.monster/phoenixtghelper_bot/webhook.php';
$secret = defined('WEBHOOK_SECRET') ? WEBHOOK_SECRET : '';

$params = [
    'url'             => $url,
    'allowed_updates' => ['message', 'callback_query'],
];
if ($secret) {
    $params['secret_token'] = $secret;
}

$ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/setWebhook');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
echo "setWebhook response:\n" . curl_exec($ch) . "\n\n";
curl_close($ch);

$ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/getWebhookInfo');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
echo "getWebhookInfo:\n" . curl_exec($ch) . "\n";
curl_close($ch);
