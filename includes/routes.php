<?php
declare(strict_types=1);

/**
 * Centralised URL map — single source of truth for all internal paths.
 *
 * Usage (URL generation):
 *   url('tree', ['id' => $treeId])        → '/tree/3/'
 *   url('member.edit', ['id' => $pid])     → '/member/5/edit/'
 *   url('home')                            → '/'
 *
 * Also serves as the dev-server router:
 *   php -S localhost:8000 -t public includes/routes.php
 */

//  route name          => [path template,            PHP script,               GET param names]
const ROUTE_MAP = [
    'home'                => ['/',                      'index.php',              []],
    'login'               => ['/login/',                'login.php',              []],
    'tree'                => ['/tree/{id}/',             'tree.php',               ['id']],
    'tree.edit'           => ['/tree/{id}/edit/',        'edit_tree.php',          ['id']],
    'tree.add_member'     => ['/tree/{id}/add-member/',  'add_member.php',         ['tree_id']],
    'member.edit'         => ['/member/{id}/edit/',      'edit_member.php',        ['id']],
    'member.delete'       => ['/member/delete/',         'delete_member.php',      []],
    'relationship.delete' => ['/relationship/delete/',   'delete_relationship.php', []],
    'photo'               => ['/photo/{id}/',            'photo.php',              ['id']],
];

function url(string $name, array $params = []): string {
    if (!isset(ROUTE_MAP[$name])) {
        throw new \InvalidArgumentException("Unknown route: {$name}");
    }
    $path = ROUTE_MAP[$name][0];
    foreach ($params as $key => $value) {
        $path = str_replace('{' . $key . '}', (string)$value, $path);
    }
    return $path;
}

// ── Dev-server router (php -S) ───────────────────────────────────────────────
if (php_sapi_name() === 'cli-server') {
    $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = $_SERVER['DOCUMENT_ROOT'] . $uri;

    // Serve existing static files directly
    if ($uri !== '/' && is_file($file)) {
        return false;
    }

    // Build regex from each route's path template
    foreach (ROUTE_MAP as [$pathTemplate, $script, $paramNames]) {
        $regex = '#^' . preg_replace('#\{[^}]+\}#', '(\d+)', $pathTemplate) . '$#';
        if (preg_match($regex, $uri, $matches)) {
            foreach ($paramNames as $i => $key) {
                $_GET[$key] = $matches[$i + 1];
            }
            require $_SERVER['DOCUMENT_ROOT'] . '/' . $script;
            return true;
        }
    }

    http_response_code(404);
    echo '404 Not Found';
    return true;
}
