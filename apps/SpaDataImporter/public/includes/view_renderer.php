<?php
// /importFromSpa/includes/view_renderer.php

/**
 * Renders the HTML shell for the React application.
 *
 * @param array $initial_app_data_for_react Data to pass to the React app via window.bx24InitialData.
 * @param string $app_base_script_name The SCRIPT_NAME dificuldadesfor window.appConfig.apiBaseUrl.
 */
function render_app_shell(array $initial_app_data_for_react, string $app_base_script_name)
{
    // Убедимся, что DATA_DIR определена для логирования, если манифест не найден
    if (!defined('DATA_DIR')) {
        $configPath = __DIR__ . '/../config.php';
        if (file_exists($configPath)) {
            require_once $configPath; // Загрузит DATA_DIR
        } else {
            // Фолбэк, если config.php не найден (не должно происходить в нормальной работе)
            if (!defined('DATA_DIR_VIEW_FALLBACK')) {
                define('DATA_DIR_VIEW_FALLBACK', __DIR__ . '/../data/');
            }
        }
    }
    $logDataDirForView = defined('DATA_DIR') ? DATA_DIR : (defined('DATA_DIR_VIEW_FALLBACK') ? DATA_DIR_VIEW_FALLBACK : __DIR__ . '/../data/');


    // --- Подготовка путей к статике Vite ---
    $viteManifestPath = __DIR__ . '/../dist/.vite/manifest.json'; // Путь к манифесту Vite относительно этого файла
    $jsEntry = '../dist/assets/index.js'; // Фоллбэк, если манифеста нет (путь от корня приложения)
    $cssEntries = [];

    // Корректируем пути для использования из корня приложения
    $basePathForAssets = rtrim(dirname($app_base_script_name), '/\\'); // Базовый путь для URL
    if ($basePathForAssets === '.' || $basePathForAssets === '') { // Если index.php в корне сайта
        $basePathForAssets = '';
    }

    // JS entry должен быть относительным от корня сайта, а не от __DIR__
    // Если vite.config.js настроен с base: './', то пути в манифесте будут относительны dist/
    // Мы формируем URL от корня приложения.

    $jsEntryUrl = $basePathForAssets . '/dist/assets/index.js'; // Фоллбэк URL

    if (file_exists($viteManifestPath)) {
        $manifestJson = @file_get_contents($viteManifestPath);
        if ($manifestJson === false) {
            if (is_writable($logDataDirForView)) {
                error_log("VIEW_RENDERER_ERROR: Failed to read Vite manifest file: " . $viteManifestPath);
            }
        } else {
            $manifest = json_decode($manifestJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (is_writable($logDataDirForView)) {
                    error_log("VIEW_RENDERER_ERROR: Failed to decode Vite manifest JSON. Error: " . json_last_error_msg() . ". Path: " . $viteManifestPath);
                }
            } elseif ($manifest) {
                $mainEntryKey = null;
                $possibleEntryKeys = ['src/main.tsx', 'src/main.ts', 'index.html', 'main.tsx', 'main.ts']; // Добавьте свои, если нужно

                foreach ($possibleEntryKeys as $key) {
                    if (isset($manifest[$key])) {
                        $mainEntryKey = $key;
                        break;
                    }
                }
                if (!$mainEntryKey && !empty($manifest)) {
                    foreach ($manifest as $key => $details) {
                        if (is_array($details) && isset($details['isEntry']) && $details['isEntry'] === true) {
                            $mainEntryKey = $key;
                            break;
                        }
                    }
                    if (!$mainEntryKey)
                        $mainEntryKey = array_key_first($manifest);
                }

                if ($mainEntryKey && isset($manifest[$mainEntryKey]['file'])) {
                    $jsEntryUrl = $basePathForAssets . '/dist/' . $manifest[$mainEntryKey]['file'];
                    if (isset($manifest[$mainEntryKey]['css']) && is_array($manifest[$mainEntryKey]['css'])) {
                        foreach ($manifest[$mainEntryKey]['css'] as $cssFile) {
                            $cssEntries[] = $basePathForAssets . '/dist/' . $cssFile;
                        }
                    }
                } else {
                    if (is_writable($logDataDirForView)) {
                        error_log("VIEW_RENDERER_ERROR: Vite manifest: main entry '{$mainEntryKey}' or its 'file' property not found. Path: {$viteManifestPath}. Manifest: " . print_r($manifest, true));
                    }
                }
            }
        }
    } else {
        if (is_writable($logDataDirForView)) {
            error_log("VIEW_RENDERER_WARNING: Vite manifest not found: " . $viteManifestPath . ". Using fallback JS/CSS paths.");
        }
    }

    // Убедимся, что заголовки еще не отправлены перед выводом HTML
    if (headers_sent($file, $line)) {
        error_log("VIEW_RENDERER_ERROR: Headers already sent in {$file} on line {$line}. Cannot render app shell.");
        return; // Не можем рендерить, если заголовки ушли
    }

    // Установка Content-Type, если еще не установлен (например, config.php его уже установил)
    // Это больше для безопасности, если render_app_shell вызывается до установки заголовков.
    $contentTypeSet = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            $contentTypeSet = true;
            break;
        }
    }
    if (!$contentTypeSet) {
        header('Content-Type: text/html; charset=utf-8');
    }

    ?>
    <!DOCTYPE html>
    <html lang="ru">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SPA Data Importer</title>
        <?php foreach ($cssEntries as $cssFile): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile); ?>">
        <?php endforeach; ?>
        <script>
            window.bx24InitialData = <?php echo json_encode($initial_app_data_for_react ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            window.appConfig = {
                apiBaseUrl: '<?php echo htmlspecialchars($app_base_script_name); ?>' /* Используем переданное имя скрипта */
            };
            // Для отладки в консоли браузера
            console.log('bx24InitialData:', window.bx24InitialData);
            console.log('appConfig:', window.appConfig);
        </script>
    </head>

    <body>
        <noscript>You need to enable JavaScript to run this app.</noscript>
        <div id="root">
            <p style="padding:20px; text-align:center; font-family: sans-serif;">Loading Application...</p>
        </div>
        <script type="module" src="<?php echo htmlspecialchars($jsEntryUrl); ?>"></script>
    </body>

    </html>
    <?php
} // Конец функции render_app_shell
?>