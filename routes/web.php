<?php

declare(strict_types=1);

use App\Core\Http\Response;
use App\Core\Http\Request;
use App\Core\Routing\RouterFacade as Router;
use App\Controllers\Api\V1\AvailabilityController;
use App\Controllers\Api\V1\RequestController;

if (!function_exists('serveMediaFileFromStorage')) {
    /**
     * Serves a media file from storage with a detected mime type.
     *
     * @param string $absolutePath Absolute filesystem path to the media file.
     * @return Response JSON 404 response when invalid/not found, otherwise file response.
     */
    function serveMediaFileFromStorage(string $absolutePath): Response
    {
        $storageRoot = realpath(base_path('storage/media'));
        $resolved = realpath($absolutePath);

        if ($storageRoot === false || $resolved === false || !str_starts_with($resolved, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($resolved)) {
            return Response::json([
                'error' => true,
                'message' => 'Resource not found',
            ], 404);
        }

        $mimeType = 'application/octet-stream';
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($resolved);
            if (is_string($detected) && trim($detected) !== '') {
                $mimeType = trim($detected);
            }
        }

        if ($mimeType === 'application/octet-stream' && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_file($finfo, $resolved);
                @finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    $mimeType = trim($detected);
                }
            }
        }

        if ($mimeType === 'application/octet-stream' && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($resolved);
            if (is_array($imageInfo) && isset($imageInfo['mime']) && is_string($imageInfo['mime']) && trim($imageInfo['mime']) !== '') {
                $mimeType = trim($imageInfo['mime']);
            }
        }

        $body = (string) file_get_contents($resolved);

        return new Response($body, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}

/**
 * Resolves and serves dated media files from storage.
 *
 * @param Request $request Request containing year, month, and file route attributes.
 * @return Response Media file response or JSON 404 response.
 */
Router::get('/storage/media/{year}/{month}/{file}', function (Request $request): Response {
    $year = trim((string) $request->attribute('year', ''));
    $month = trim((string) $request->attribute('month', ''));
    $file = trim((string) $request->attribute('file', ''));

    if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month) || $file === '') {
        return Response::json([
            'error' => true,
            'message' => 'Resource not found',
        ], 404);
    }

    return serveMediaFileFromStorage(base_path('storage/media/' . $year . '/' . $month . '/' . $file));
})->name('storage.media.by_date');

/**
 * Resolves and serves media files from the persistent storage directory.
 *
 * @param Request $request Request containing file route attribute.
 * @return Response Media file response or JSON 404 response.
 */
Router::get('/storage/media/persistent/{file}', function (Request $request): Response {
    $file = trim((string) $request->attribute('file', ''));
    if ($file === '' || str_contains($file, '/') || str_contains($file, '\\')) {
        return Response::json([
            'error' => true,
            'message' => 'Resource not found',
        ], 404);
    }

    return serveMediaFileFromStorage(base_path('storage/media/persistent/' . $file));
})->name('storage.media.persistent');

/**
 * Resolves and serves media files from the referenced projects storage directory.
 *
 * @param Request $request Request containing file route attribute.
 * @return Response Media file response or JSON 404 response.
 */
Router::get('/storage/media/referenced_projects/{file}', function (Request $request): Response {
    $file = trim((string) $request->attribute('file', ''));
    if ($file === '' || str_contains($file, '/') || str_contains($file, '\\')) {
        return Response::json([
            'error' => true,
            'message' => 'Resource not found',
        ], 404);
    }

    return serveMediaFileFromStorage(base_path('storage/media/referenced_projects/' . $file));
})->name('storage.media.referenced_projects');

// Backward-compatible route for legacy filenames that were stored without year/month path.
/**
 * Resolves and serves legacy media files stored without dated subdirectories.
 *
 * @param Request $request Request containing file route attribute.
 * @return Response Media file response or JSON 404 response.
 */
Router::get('/storage/media/{file}', function (Request $request): Response {
    $file = trim((string) $request->attribute('file', ''));
    if ($file === '') {
        return Response::json([
            'error' => true,
            'message' => 'Resource not found',
        ], 404);
    }

    $directPath = base_path('storage/media/' . $file);
    if (is_file($directPath)) {
        return serveMediaFileFromStorage($directPath);
    }

    $matches = glob(base_path('storage/media/*/*/' . $file));
    if (is_array($matches) && $matches !== []) {
        return serveMediaFileFromStorage((string) $matches[0]);
    }

    return Response::json([
        'error' => true,
        'message' => 'Resource not found',
    ], 404);
})->name('storage.media.legacy');

/**
 * Returns a lightweight JSON status payload.
 *
 * @return Response JSON response with application status info.
 */
Router::get('/status.json', fn () => Response::json([
    'success' => true,
    'data' => [
        'name' => config('app.name'),
        'message' => 'Backend foundation is running',
    ],
]))->name('status.api');

/**
 * Returns an empty response for favicon requests.
 *
 * @return Response Empty no-content response.
 */
Router::get('/favicon.ico', fn () => Response::noContent())->name('favicon');

/**
 * Renders the HTML system status page with runtime checks.
 *
 * @return Response HTML response with status details and appropriate status code.
 */
Router::get('/status', function (): Response {
    $checks = [];
    $issues = [];

    $checks[] = [
        'name' => 'App Modus',
        'state' => config('app.debug', false) ? 'warn' : 'ok',
        'detail' => config('app.debug', false)
            ? 'Debug-Modus ist aktiv. Für Produktion APP_DEBUG=false setzen.'
            : 'Produktionsmodus aktiv.',
    ];

    $cacheWritable = is_writable(base_path('storage/cache'));
    $checks[] = [
        'name' => 'Cache-Verzeichnis',
        'state' => $cacheWritable ? 'ok' : 'error',
        'detail' => $cacheWritable
            ? 'storage/cache ist beschreibbar.'
            : 'storage/cache ist nicht beschreibbar.',
    ];

    $logsWritable = is_writable(base_path('storage/logs'));
    $checks[] = [
        'name' => 'Log-Verzeichnis',
        'state' => $logsWritable ? 'ok' : 'error',
        'detail' => $logsWritable
            ? 'storage/logs ist beschreibbar.'
            : 'storage/logs ist nicht beschreibbar.',
    ];

    $pdoExtLoaded = extension_loaded('pdo_mysql');
    $checks[] = [
        'name' => 'PDO MySQL',
        'state' => $pdoExtLoaded ? 'ok' : 'error',
        'detail' => $pdoExtLoaded
            ? 'pdo_mysql ist geladen.'
            : 'pdo_mysql fehlt. Datenbankzugriff ist nicht möglich.',
    ];

    foreach ($checks as $check) {
        if (($check['state'] ?? 'error') !== 'ok') {
            $issues[] = sprintf('%s: %s', $check['name'], $check['detail']);
        }
    }

    $summary = $issues === [] ? 'ok' : 'degraded';
    $statusCode = $issues === [] ? 200 : 503;
    $generatedAt = date(DATE_ATOM);

    ob_start();
    require base_path('public/ui/_templates/status-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, $statusCode, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('status');

/**
 * Renders the home page.
 *
 * @return Response HTML response for the home page.
 */
Router::get('/', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/home-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('home');

/**
 * Renders the leistungen page.
 *
 * @return Response HTML response for the leistungen page.
 */
Router::get('/leistungen', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/leistungen-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('leistungen');

/**
 * Renders the referenzen page.
 *
 * @return Response HTML response for the referenzen page.
 */
Router::get('/referenzen', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/referenzen-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('referenzen');

/**
 * Renders the referenzen page.
 *
 * @return Response HTML response for the referenzen page.
 */
Router::get('/zielgruppen', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/zielgruppen-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('zielgruppen');

/**
 * Renders the ueber-uns page.
 *
 * @return Response HTML response for the ueber-mich page.
 */
Router::get('/ueber-uns', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/ueber-uns-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('ueber-mich');

/**
 * Renders the impressum page.
 *
 * @return Response HTML response for the impressum page.
 */
Router::get('/impressum', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/impressum-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('impressum');

/**
 * Renders the datenschutz page.
 *
 * @return Response HTML response for the datenschutz page.
 */
Router::get('/datenschutz', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/datenschutz-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('datenschutz');

/**
 * Renders the widerruf page.
 *
 * @return Response HTML response for the widerruf page.
 **/
/*
Router::get('/widerruf', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/widerruf-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('widerruf');
*/

/**
 * Renders the AGB page.
 *
 * @return Response HTML response for the AGB page.
 */
Router::get('/agb', function (): Response {
    ob_start();
    require base_path('public/ui/_templates/agb-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
})->name('agb');

/**
 * Handles unmatched routes and renders API or HTML 404 responses.
 *
 * @param Request $request Incoming request used to determine API or web fallback.
 * @return Response JSON 404 for API routes or HTML 404 page for web routes.
 */
Router::fallback(function (Request $request): Response {
    if (str_starts_with($request->path(), '/api')) {
        return Response::json([
            'error' => true,
            'message' => 'Resource not found',
        ], 404);
    }

    $code = 404;
    $title = 'Seite nicht gefunden';
    $message = 'Die angeforderte Seite existiert nicht oder wurde verschoben.';
    $hints = [
        'Prüfe die URL auf Schreibfehler.',
        'Nutze die Navigation für gültige Bereiche.',
        'Starte bei Bedarf auf der Startseite neu.',
    ];

    ob_start();
    require base_path('public/ui/_templates/error-page.php');
    $html = (string) ob_get_clean();

    return new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
});
