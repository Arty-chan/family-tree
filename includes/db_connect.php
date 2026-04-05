<?php
declare(strict_types=1);

define('DB_PATH',         __DIR__ . '/../family_tree.db');
define('UPLOADS_DIR',     __DIR__ . '/../uploads/');
define('MAX_PHOTO_BYTES', 5 * 1024 * 1024);   // 5 MB

require_once __DIR__ . '/routes.php';

function get_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON;');
    $db->exec('PRAGMA journal_mode  = WAL;');
    $db->exec('PRAGMA busy_timeout  = 5000;');
    init_schema($db);
    return $db;
}

function init_schema(PDO $db): void {
    $db->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS trees (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            title         TEXT    NOT NULL,
            password_hash TEXT,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS persons (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            tree_id           INTEGER NOT NULL,
            name              TEXT    NOT NULL,
            birth_year        INTEGER,
            death_year        INTEGER,
            photo_filename    TEXT,
            birth_year_approx INTEGER NOT NULL DEFAULT 0,
            death_year_approx INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS relationships (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            tree_id              INTEGER NOT NULL,
            person1_id           INTEGER NOT NULL,
            person2_id           INTEGER NOT NULL,
            relationship_type    TEXT    NOT NULL,  -- 'parent_child' | 'spouse' | 'cousin'
            year_married         INTEGER,
            year_separated       INTEGER,
            year_married_approx  INTEGER NOT NULL DEFAULT 0,
            year_separated_approx INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (tree_id)    REFERENCES trees(id)   ON DELETE CASCADE,
            FOREIGN KEY (person1_id) REFERENCES persons(id) ON DELETE CASCADE,
            FOREIGN KEY (person2_id) REFERENCES persons(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    SQL);

    // ── Initial admin password ───────────────────────────────────────────────
    $hasAdmin = (int)$db->query("SELECT COUNT(*) FROM settings WHERE key = 'admin_password_hash'")->fetchColumn();
    if (!$hasAdmin) {
        $adminPw = getenv('FT_ADMIN_PASSWORD');
        if (!$adminPw) {
            http_response_code(500);
            echo 'Set the FT_ADMIN_PASSWORD environment variable before first run. See README for details.';
            exit(1);
        }
        $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)")
           ->execute(['admin_password_hash', password_hash($adminPw, PASSWORD_BCRYPT)]);
    }
}

/** Strip tags, trim, cap length. Safe for any UTF-8 text. */
function clean_text(string $v, int $max = 255): string {
    return mb_substr(strip_tags(trim($v)), 0, $max, 'UTF-8');
}

/** Validate a year (1–current year) or return null. */
function clean_year(mixed $v): ?int {
    if ($v === null || $v === '') return null;
    $max = (int)date('Y');
    $i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    return ($i !== false) ? $i : null;
}

/**
 * Validate and save an uploaded photo.
 *
 * Accepted formats : JPG, PNG, GIF, WebP (via GD)
 * All formats are converted and saved as JPG.
 *
 * - Max size   : MAX_PHOTO_BYTES (5 MB)
 * - Filename   : alphanumeric person name + random hex suffix + .jpg
 *
 * Returns the saved filename, or null if no file was uploaded.
 */
function handle_photo_upload(array $file, string $personName, int $treeId): ?string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('photo.upload_error', (string) $file['error']));
    }
    if ($file['size'] > MAX_PHOTO_BYTES) {
        throw new RuntimeException(t('photo.too_large'));
    }

    $tmp = $file['tmp_name'];

    // ── GD path ──────────────────────────────────────────────────────────
    $info = @getimagesize($tmp);
    if (!$info) {
        throw new RuntimeException(t('photo.invalid_image'));
    }
    [$w, $h] = $info;
    $gdImage = match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => imagecreatefrompng($tmp),
        IMAGETYPE_GIF  => imagecreatefromgif($tmp),
        IMAGETYPE_WEBP => imagecreatefromwebp($tmp),
        default => throw new RuntimeException(t('photo.unsupported_format')),
    };
    if (!$gdImage) {
        throw new RuntimeException(t('photo.unreadable'));
    }

    // ── Save as JPG ───────────────────────────────────────────────────────────
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $personName);
    if ($base === '') {
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '',
                    pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'photo';
    }
    $filename = $base . '_' . bin2hex(random_bytes(4)) . '.jpg';

    $dir = UPLOADS_DIR . $treeId . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $saved = imagejpeg($gdImage, $dir . $filename, 88);  // quality 88
    imagedestroy($gdImage);

    if (!$saved) {
        throw new RuntimeException(t('photo.save_failed'));
    }

    return $filename;
}

/** Delete a photo file from the uploads directory. */
function delete_photo(?string $filename, int $treeId): void {
    if (!$filename) return;
    $path = UPLOADS_DIR . $treeId . '/' . basename($filename);
    if (is_file($path)) @unlink($path);
}

/** HTML-escape a value for safe output. */
function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect and stop execution. */
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// ── Relationship helpers ─────────────────────────────────────────────────────

/** Convert form rel_type value to DB relationship_type. */
function typeToStored(string $t): string {
    return match ($t) {
        'child', 'parent' => 'parent_child',
        'spouse'          => 'spouse',
        'cousin'          => 'cousin',
        default           => 'parent_child',
    };
}

/** Load all relationships for a person within a tree. */
function loadRelationships(PDO $db, int $personId, int $treeId): array {
    $stmt = $db->prepare(
        'SELECT r.id, r.relationship_type, r.person1_id, r.person2_id,
                r.year_married, r.year_separated,
                r.year_married_approx, r.year_separated_approx,
                p1.name AS name1, p2.name AS name2
         FROM   relationships r
         JOIN   persons p1 ON p1.id = r.person1_id
         JOIN   persons p2 ON p2.id = r.person2_id
         WHERE  (r.person1_id = ? OR r.person2_id = ?)
           AND  r.tree_id = ?
         ORDER  BY r.id'
    );
    $stmt->execute([$personId, $personId, $treeId]);
    return $stmt->fetchAll();
}

/** Insert a relationship record. */
function saveRelationship(
    PDO $db, int $treeId, int $personId, int $relPersonId,
    string $relType, ?int $ym = null, ?int $ys = null,
    int $yma = 0, int $ysa = 0
): void {
    switch ($relType) {
        case 'child':
            $db->prepare('INSERT INTO relationships (tree_id,person1_id,person2_id,relationship_type) VALUES (?,?,?,?)')
               ->execute([$treeId, $relPersonId, $personId, 'parent_child']);
            break;
        case 'parent':
            $db->prepare('INSERT INTO relationships (tree_id,person1_id,person2_id,relationship_type) VALUES (?,?,?,?)')
               ->execute([$treeId, $personId, $relPersonId, 'parent_child']);
            break;
        case 'spouse':
            $p1 = min($personId, $relPersonId); $p2 = max($personId, $relPersonId);
            $db->prepare('INSERT INTO relationships (tree_id,person1_id,person2_id,relationship_type,year_married,year_separated,year_married_approx,year_separated_approx) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$treeId, $p1, $p2, 'spouse', $ym, $ys, $yma, $ysa]);
            break;
        case 'cousin':
            $p1 = min($personId, $relPersonId); $p2 = max($personId, $relPersonId);
            $db->prepare('INSERT INTO relationships (tree_id,person1_id,person2_id,relationship_type) VALUES (?,?,?,?)')
               ->execute([$treeId, $p1, $p2, 'cousin']);
            break;
    }
}

/** Format spouse years as "1965 – 1990 (?)" for display. */
function formatSpouseYears(array $rel): string {
    $ymLabel = $rel['year_married']   ? e($rel['year_married'])   . (!empty($rel['year_married_approx'])   ? ' (?)' : '') : '';
    $ysLabel = $rel['year_separated'] ? e($rel['year_separated']) . (!empty($rel['year_separated_approx']) ? ' (?)' : '') : '';
    return implode(' – ', array_filter([$ymLabel, $ysLabel]));
}

/** Human-readable description of a relationship from a person's perspective. */
function relDescription(array $rel, int $personId): string {
    $pid = (int)$rel['person1_id'];
    $other = ($pid === $personId) ? $rel['name2'] : $rel['name1'];
    switch ($rel['relationship_type']) {
        case 'parent_child':
            return ($pid === $personId)
                ? t('member.rel_parent') . ' ' . $other
                : t('member.rel_child') . ' '  . $other;
        case 'spouse':
            $suffix = '';
            if ($rel['year_married']) {
                $suffix .= ', ' . t('member.rel_married') . ' ' . $rel['year_married'];
                if (!empty($rel['year_married_approx'])) $suffix .= ' (?)';
            }
            if ($rel['year_separated']) {
                $suffix .= ', ' . t('member.rel_separated') . ' ' . $rel['year_separated'];
                if (!empty($rel['year_separated_approx'])) $suffix .= ' (?)';
            }
            return t('member.rel_spouse') . ' ' . $other . $suffix;
        case 'cousin':
            return t('member.rel_cousin') . ' ' . $other;
    }
    return $rel['relationship_type'] . ' of ' . $other;
}

/** Short badge label for a relationship type. */
function relBadge(string $type): string {
    return match ($type) {
        'parent_child' => t('rel_badge.parent_child'),
        'spouse'       => t('rel_badge.spouse'),
        'cousin'       => t('rel_badge.cousin'),
        default        => $type,
    };
}