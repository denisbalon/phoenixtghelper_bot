<?php

function instructionsFilePath(): string {
    return __DIR__ . '/../instructions.html';
}

function instructionsFlagPath(): string {
    return __DIR__ . '/../pending_instructions.flag';
}

function defaultInstructionsText(): string {
    return "📌 <b>Повторна інструкція:</b>\n\n"
         . "1. Реєструвати краще з мобільного інтернета, а не домашнього вайфай.\n"
         . "2. Ім'я аккаунта, пароль — будь які на ваш вибір.\n"
         . "3. Телефон чи пошту можете використувати вашу, потім відключимо — <b>Ваші особисті данні НАМ НЕ ПОТРІБНІ!!!</b>\n"
         . "4. Профіль можна <b>НЕ</b> оформлювати.\n"
         . "5. Нижче фото профіля.\n"
         . "6. Нижче список людей, щоб підписатися.\n"
         . "7. Нижче два відео с підписами до кожного.\n"
         . "8. Між викладанням відео зробіть паузу мінімум в 1 годину.\n\n"
         . "🔥 <b>САМЕ ГОЛОВНЕ:</b> включіть <b>професійний режим автора</b>, щоб була повна статистика!!!\n"
         . "👉 <a href=\"https://help.instagram.com/502981923235522\">тут інструкція</a>";
}

function getInstructionsText(): string {
    $path = instructionsFilePath();
    if (is_readable($path)) {
        $t = file_get_contents($path);
        if ($t !== false && trim($t) !== '') {
            return $t;
        }
    }
    return defaultInstructionsText();
}

function saveInstructionsText(string $text): bool {
    // Atomic replace: write to temp file under exclusive lock, then rename.
    // POSIX rename() within the same filesystem is atomic, so concurrent
    // readers always see either the old or new content, never a partial write.
    $path = instructionsFilePath();
    $tmp  = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $text, LOCK_EX) === false) {
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function armInstructionsEdit(): void {
    @file_put_contents(instructionsFlagPath(), (string) time());
}

function clearInstructionsEdit(): void {
    @unlink(instructionsFlagPath());
}

function isInstructionsEditPending(): bool {
    return is_file(instructionsFlagPath());
}

function handleAdminInstructionsCmd(int $chatId, string $body): void {
    if ($body !== '') {
        // Validate by sending the preview FIRST; only persist if Telegram
        // accepts the HTML. Otherwise a malformed edit would poison every
        // future /N batch until someone manually repairs the file.
        $preview = sendMessage($chatId, $body, null, 'HTML', true);
        if ($preview === null) {
            sendMessage($chatId, '❌ Telegram rejected that HTML — instructions NOT updated. Allowed tags: &lt;b&gt;, &lt;i&gt;, &lt;a href&gt;, etc. See logs/error.log for details.', null, 'HTML', true);
            return;
        }
        if (!saveInstructionsText($body)) {
            sendMessage($chatId, 'Failed to write instructions.html on host.');
            return;
        }
        clearInstructionsEdit();
        sendMessage($chatId, '✅ Instructions updated (preview above).');
        return;
    }
    armInstructionsEdit();
    sendMessage($chatId, 'Current instructions:');
    sleep(1);
    sendMessage($chatId, getInstructionsText(), null, 'HTML', true);
    sleep(1);
    sendMessage($chatId, 'Send the new text now (Telegram HTML allowed: &lt;b&gt;, &lt;i&gt;, &lt;a href&gt;), or /cancel.', null, 'HTML', true);
}

function handleAdminInstructionsCapture(int $chatId, string $text): void {
    // Same validate-first pattern as the inline /instructions <body> path.
    // Pending-edit flag stays armed on preview failure so admin can retry
    // without re-issuing /instructions.
    $preview = sendMessage($chatId, $text, null, 'HTML', true);
    if ($preview === null) {
        sendMessage($chatId, '❌ Telegram rejected that HTML — instructions NOT updated. Edit still armed; try again or /cancel.', null, 'HTML', true);
        return;
    }
    if (!saveInstructionsText($text)) {
        sendMessage($chatId, 'Failed to write instructions.html on host.');
        return;
    }
    clearInstructionsEdit();
    sendMessage($chatId, '✅ Instructions updated (preview above).');
}

function loadPacksManifest(int $chatId): ?array {
    $manifestPath = __DIR__ . '/../packs.json';
    $manifest = is_readable($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    if (!is_array($manifest) || !$manifest) {
        logError('packs.json missing or invalid');
        sendMessage($chatId, 'packs.json missing on host — upload it.');
        return null;
    }
    return $manifest;
}

function handleAdminIndex(int $chatId): void {
    $manifest = loadPacksManifest($chatId);
    if ($manifest === null) {
        return;
    }
    $lines = ['<b>Content packs</b> (' . count($manifest) . ')', ''];
    foreach ($manifest as $i => $pack) {
        $n = $i + 1;
        $cmd = sprintf('/%03d', $n);
        $label = $pack['label'] ?? ('batch ' . sprintf('%03d', $n));
        $lines[] = $cmd . ' — ' . $label;
    }
    sendMessage($chatId, implode("\n", $lines), null, 'HTML', true);
}

function handleAdminBatch(int $chatId, int $idx): void {
    $manifest = loadPacksManifest($chatId);
    if ($manifest === null) {
        return;
    }
    $count = count($manifest);
    if ($idx < 1 || $idx > $count) {
        sendMessage($chatId, "No batch {$idx}. Valid range: 1–{$count}.");
        return;
    }
    $pack = $manifest[$idx - 1];

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    @ignore_user_abort(true);
    @set_time_limit(0);

    $base = __DIR__ . '/..';
    $delay = 3; // per-send pacing — Telegram per-chat bursts > ~1/s trip 429.

    // Label fallback ensures we never sendMessage('') — Telegram rejects empty
    // text with 400. Use the same template as handleAdminIndex.
    $labelText = $pack['label'] ?? sprintf('batch %03d', $idx);

    // Abort the batch if the FIRST send fails hard. telegramAPI() retries 429s
    // internally, so a null return means a non-retryable failure (bad chat,
    // bot blocked, network gone) — every later send to the same chat will
    // also fail, so a half-delivered batch is worse than failing fast.
    if (sendMessage($chatId, $labelText) === null) {
        logError("handleAdminBatch idx={$idx}: label send failed, aborting batch");
        return;
    }
    sleep($delay);

    sendMessage($chatId, getInstructionsText(), null, 'HTML', true);
    sleep($delay);

    $photoPath = $base . '/' . ($pack['photo'] ?? '');
    if (is_file($photoPath)) {
        sendPhoto($chatId, $photoPath);
    } else {
        logError("handleAdminBatch: photo missing {$photoPath}");
        sendMessage($chatId, '[photo missing: ' . ($pack['photo'] ?? '?') . ']');
    }
    sleep($delay);

    $igs = $pack['ig_urls'] ?? [];
    if ($igs) {
        sendMessage($chatId, implode("\n", $igs), null, 'HTML', true);
        sleep($delay);
    }

    foreach (($pack['videos'] ?? []) as $v) {
        $vpath = $base . '/' . ($v['path'] ?? '');
        $cap = $v['caption'] ?? null;
        if (is_file($vpath)) {
            sendVideo($chatId, $vpath, $cap);
        } else {
            logError("handleAdminBatch: video missing {$vpath}");
            sendMessage($chatId, '[video missing: ' . ($v['path'] ?? '?') . ']');
        }
        sleep($delay);
    }
}
