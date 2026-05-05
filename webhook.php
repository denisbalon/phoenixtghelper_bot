<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

const ADMIN_USER_ID = 8707720355;

try {
    $secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (defined('WEBHOOK_SECRET') && WEBHOOK_SECRET && $secret !== WEBHOOK_SECRET) {
        logError('Webhook secret mismatch');
        http_response_code(403);
        exit;
    }

    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    if (!$update) {
        http_response_code(200);
        exit;
    }

    $userId = null;
    $chatId = null;
    $text = null;

    if (isset($update['message'])) {
        $msg = $update['message'];
        $userId = (int) ($msg['from']['id'] ?? 0);
        $chatId = (int) ($msg['chat']['id'] ?? 0);
        $text = $msg['text'] ?? null;
    } elseif (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        $userId = (int) ($cb['from']['id'] ?? 0);
        $chatId = (int) ($cb['message']['chat']['id'] ?? 0);
    } else {
        http_response_code(200);
        exit;
    }

    if ($userId !== ADMIN_USER_ID) {
        http_response_code(200);
        exit;
    }

    require_once __DIR__ . '/handlers/start.php';

    if ($text !== null
            && preg_match('~^/instructions(?:@\w+)?(?:\s+(.+))?$~is', $text, $im)) {
        handleAdminInstructionsCmd($chatId, trim($im[1] ?? ''));
        http_response_code(200);
        exit;
    }

    if ($text !== null && isInstructionsEditPending()) {
        if (strpos($text, '/') === 0) {
            clearInstructionsEdit();
            if (preg_match('~^/cancel(?:@\w+)?\s*$~i', $text)) {
                sendMessage($chatId, 'Instructions edit cancelled.');
                http_response_code(200);
                exit;
            }
        } else {
            handleAdminInstructionsCapture($chatId, $text);
            http_response_code(200);
            exit;
        }
    }

    if ($text !== null && strpos($text, '/start') === 0) {
        handleAdminIndex($chatId);
        http_response_code(200);
        exit;
    }

    if ($text !== null && preg_match('~^/(\d+)(?:@\w+)?\s*$~', $text, $m)) {
        handleAdminBatch($chatId, (int) $m[1]);
        http_response_code(200);
        exit;
    }

} catch (Throwable $e) {
    logError('Uncaught exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

http_response_code(200);
