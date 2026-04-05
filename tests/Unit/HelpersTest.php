<?php

declare(strict_types=1);

/**
 * Unit tests for pure helper functions in db_connect.php and auth.php.
 *
 * These cover edge-case logic that E2E tests can't easily exercise:
 *   - input sanitisation boundaries
 *   - year validation ranges
 *   - HTML escaping (XSS prevention)
 *   - relationship type mapping
 *   - spouse year formatting combinations
 *   - redirect-target validation (open redirect prevention)
 */

// Load the functions under test (db_connect.php pulls in routes.php)
require_once __DIR__ . '/../../includes/db_connect.php';

// ─── clean_text ──────────────────────────────────────────────────────────────

test('clean_text trims whitespace', function () {
    expect(clean_text('  hello  '))->toBe('hello');
});

test('clean_text strips HTML tags', function () {
    expect(clean_text('<b>bold</b> & <script>xss</script>'))->toBe('bold & xss');
});

test('clean_text respects max length', function () {
    expect(clean_text('abcdef', 3))->toBe('abc');
});

test('clean_text handles multibyte UTF-8', function () {
    // 5 CJK characters, limit to 3
    expect(clean_text('好的家庭树', 3))->toBe('好的家');
});

test('clean_text defaults to 255 max', function () {
    $long = str_repeat('a', 300);
    expect(clean_text($long))->toHaveLength(255);
});

test('clean_text returns empty for empty input', function () {
    expect(clean_text(''))->toBe('');
    expect(clean_text('   '))->toBe('');
});

// ─── clean_year ──────────────────────────────────────────────────────────────

test('clean_year returns valid year', function () {
    expect(clean_year(1990))->toBe(1990);
    expect(clean_year('1990'))->toBe(1990);
});

test('clean_year returns null for empty/null', function () {
    expect(clean_year(null))->toBeNull();
    expect(clean_year(''))->toBeNull();
});

test('clean_year rejects year 0 and negative', function () {
    expect(clean_year(0))->toBeNull();
    expect(clean_year(-5))->toBeNull();
});

test('clean_year rejects future years', function () {
    $futureYear = (int)date('Y') + 1;
    expect(clean_year($futureYear))->toBeNull();
});

test('clean_year accepts current year', function () {
    $thisYear = (int)date('Y');
    expect(clean_year($thisYear))->toBe($thisYear);
});

test('clean_year accepts year 1', function () {
    expect(clean_year(1))->toBe(1);
});

test('clean_year rejects non-numeric strings', function () {
    expect(clean_year('abc'))->toBeNull();
    expect(clean_year('19.5'))->toBeNull();
});

// ─── e (HTML escaping) ──────────────────────────────────────────────────────

test('e escapes HTML special characters', function () {
    expect(e('<script>alert("xss")</script>'))
        ->toBe('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
});

test('e escapes single quotes', function () {
    expect(e("it's"))->toBe('it&#039;s');
});

test('e handles ampersands', function () {
    expect(e('a & b'))->toBe('a &amp; b');
});

test('e converts non-string to string', function () {
    expect(e(42))->toBe('42');
    expect(e(null))->toBe('');
});

// ─── typeToStored ────────────────────────────────────────────────────────────

test('typeToStored maps child to parent_child', function () {
    expect(typeToStored('child'))->toBe('parent_child');
});

test('typeToStored maps parent to parent_child', function () {
    expect(typeToStored('parent'))->toBe('parent_child');
});

test('typeToStored maps spouse', function () {
    expect(typeToStored('spouse'))->toBe('spouse');
});

test('typeToStored maps cousin', function () {
    expect(typeToStored('cousin'))->toBe('cousin');
});

test('typeToStored defaults unknown to parent_child', function () {
    expect(typeToStored('unknown'))->toBe('parent_child');
    expect(typeToStored(''))->toBe('parent_child');
});

// ─── formatSpouseYears ───────────────────────────────────────────────────────

test('formatSpouseYears with both years', function () {
    $rel = [
        'year_married' => 1965, 'year_married_approx' => 0,
        'year_separated' => 1990, 'year_separated_approx' => 0,
    ];
    expect(formatSpouseYears($rel))->toBe('1965 – 1990');
});

test('formatSpouseYears with married only', function () {
    $rel = [
        'year_married' => 1965, 'year_married_approx' => 0,
        'year_separated' => null, 'year_separated_approx' => 0,
    ];
    expect(formatSpouseYears($rel))->toBe('1965');
});

test('formatSpouseYears with separated only', function () {
    $rel = [
        'year_married' => null, 'year_married_approx' => 0,
        'year_separated' => 1990, 'year_separated_approx' => 0,
    ];
    expect(formatSpouseYears($rel))->toBe('1990');
});

test('formatSpouseYears with approx flags', function () {
    $rel = [
        'year_married' => 1965, 'year_married_approx' => 1,
        'year_separated' => 1990, 'year_separated_approx' => 1,
    ];
    expect(formatSpouseYears($rel))->toBe('1965 (?) – 1990 (?)');
});

test('formatSpouseYears with no years returns empty', function () {
    $rel = [
        'year_married' => null, 'year_married_approx' => 0,
        'year_separated' => null, 'year_separated_approx' => 0,
    ];
    expect(formatSpouseYears($rel))->toBe('');
});

test('formatSpouseYears with zero years returns empty', function () {
    $rel = [
        'year_married' => 0, 'year_married_approx' => 0,
        'year_separated' => 0, 'year_separated_approx' => 0,
    ];
    expect(formatSpouseYears($rel))->toBe('');
});

test('formatSpouseYears mixed approx', function () {
    $rel = [
        'year_married' => 1965, 'year_married_approx' => 1,
        'year_separated' => 1990, 'year_separated_approx' => 0,
    ];
    expect(formatSpouseYears($rel))->toBe('1965 (?) – 1990');
});

// ─── safe_next (open redirect prevention) ────────────────────────────────────

// safe_next lives in auth.php which starts a session — require it once
$_SERVER['REQUEST_METHOD'] ??= 'GET';
require_once __DIR__ . '/../../includes/auth.php';

test('safe_next allows simple relative path', function () {
    expect(safe_next('/tree/3/'))->toBe('/tree/3/');
});

test('safe_next allows path with query string', function () {
    expect(safe_next('/member/5/edit/?foo=bar'))->toBe('/member/5/edit/?foo=bar');
});

test('safe_next rejects external URL', function () {
    expect(safe_next('https://evil.com'))->toBe('/');
});

test('safe_next rejects protocol-relative URL', function () {
    expect(safe_next('//evil.com'))->toBe('/');
});

test('safe_next rejects path with encoded newlines', function () {
    expect(safe_next("/tree/3/\nLocation: evil.com"))->toBe('/');
});

test('safe_next allows encoded safe path', function () {
    expect(safe_next(urlencode('/tree/3/')))->toBe('/tree/3/');
});

test('safe_next rejects empty string', function () {
    expect(safe_next(''))->toBe('/');
});

test('safe_next rejects bare domain', function () {
    expect(safe_next('evil.com'))->toBe('/');
});
