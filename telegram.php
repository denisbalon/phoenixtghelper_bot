<?php

function telegramAPI(string $method, array $params, ?string $filePath = null, ?string $fileField = null, ?string &$errorRef = null): ?array {
    $errorRef = null;
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $maxFloodWait = 600; // cap retry sleep at 10 min

    // Up to 2 attempts: original + one retry after honoring Telegram's retry_after on 429.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($filePath && $fileField) {
            $sendParams = $params;
            $sendParams[$fileField] = new CURLFile($filePath);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sendParams);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            logError("Telegram API curl error [{$method}]: {$error}");
            $errorRef = "curl: {$error}";
            return null;
        }

        $decoded = json_decode($response, true);
        if ($decoded && ($decoded['ok'] ?? false)) {
            return $decoded;
        }

        // 429 flood-wait: sleep retry_after seconds, then retry once.
        $errCode = (int) ($decoded['error_code'] ?? 0);
        if ($attempt === 1 && $errCode === 429) {
            $retryAfter = (int) ($decoded['parameters']['retry_after'] ?? 0);
            if ($retryAfter > 0 && $retryAfter <= $maxFloodWait) {
                logError("Telegram 429 [{$method}]: sleeping {$retryAfter}s before retry");
                @set_time_limit(0);
                sleep($retryAfter + 1);
                continue;
            }
        }

        logError("Telegram API error [{$method}]: " . ($response ?: 'empty response'));
        $errorRef = $decoded['description'] ?? ($response ?: 'empty response');
        return null;
    }

    return null;
}

function sendMessage(int|string $chatId, string $text, ?array $inlineKeyboard = null, string $parseMode = 'HTML', bool $disableLinkPreview = false): ?array {
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => $parseMode,
    ];
    if ($inlineKeyboard !== null) {
        $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
    }
    if ($disableLinkPreview) {
        $params['link_preview_options'] = json_encode(['is_disabled' => true]);
    }
    return telegramAPI('sendMessage', $params);
}

function sendPhoto(int|string $chatId, string $photoPath, ?string $caption = null, string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id' => $chatId,
    ];
    if ($caption) {
        $params['caption'] = $caption;
        $params['parse_mode'] = $parseMode;
    }
    return telegramAPI('sendPhoto', $params, $photoPath, 'photo');
}

function sendVideo(int|string $chatId, string $videoPath, ?string $caption = null, string $parseMode = 'HTML'): ?array {
    $params = [
        'chat_id' => $chatId,
    ];
    if ($caption) {
        $params['caption'] = $caption;
        $params['parse_mode'] = $parseMode;
    }
    return telegramAPI('sendVideo', $params, $videoPath, 'video');
}

function answerCallbackQuery(string $callbackQueryId, ?string $text = null): ?array {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text) {
        $params['text'] = $text;
    }
    return telegramAPI('answerCallbackQuery', $params);
}

function logError(string $message): void {
    $logFile = LOG_FILE;
    $maxSize = 1024 * 1024; // 1MB

    // Provision the log directory on first use. deploy.sh excludes logs/ and a
    // fresh host doesn't have it, so without this every error vanishes silently.
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // Rotate if too large
    if (@filesize($logFile) > $maxSize) {
        $rotated = $logFile . '.' . date('Y-m-d-His');
        @rename($logFile, $rotated);
        // Keep only last 5 rotated files
        $dir = dirname($logFile);
        $base = basename($logFile);
        $files = @glob($dir . '/' . $base . '.*');
        if ($files && count($files) > 5) {
            sort($files);
            $toDelete = array_slice($files, 0, count($files) - 5);
            foreach ($toDelete as $f) {
                @unlink($f);
            }
        }
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
