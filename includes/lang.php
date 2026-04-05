<?php
/**
 * Lightweight i18n helper.
 *
 * Loads lang/strings.json once, provides:
 *   t(key)           — returns the string for the active language (falls back to English)
 *   t(key, ...$args) — same, with sprintf-style placeholders
 *   get_lang()       — returns 'en' or 'zh'
 *
 * Language toggle: POST to any page with `action=toggle_lang` switches between en/zh.
 */
declare(strict_types=1);

// ── Load strings (once) ──────────────────────────────────────────────────────
function _i18n_strings(): array {
    static $strings = null;
    if ($strings === null) {
        $path = __DIR__ . '/../lang/strings.json';
        $strings = json_decode(file_get_contents($path), true) ?? [];
    }
    return $strings;
}

// ── Current language ─────────────────────────────────────────────────────────
function get_lang(): string {
    return $_SESSION['ft_lang'] ?? 'en';
}

// ── Handle language toggle (call early, before output) ───────────────────────
function handle_lang_toggle(): void {
    if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($_POST['action'] ?? '') === 'toggle_lang') {
        $_SESSION['ft_lang'] = (get_lang() === 'en') ? 'zh' : 'en';
        // Redirect back to the same page (PRG pattern)
        $back = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . $back);
        exit;
    }
}

// ── Translate ────────────────────────────────────────────────────────────────
function t(string $key, string ...$args): string {
    $strings = _i18n_strings();
    $lang = get_lang();

    if (!isset($strings[$key])) {
        return $key; // key itself as fallback
    }

    $text = $strings[$key][$lang] ?? $strings[$key]['en'] ?? $key;

    if ($args) {
        $text = sprintf($text, ...$args);
    }
    return $text;
}
