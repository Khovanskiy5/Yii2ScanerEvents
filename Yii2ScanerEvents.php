<?php

/**
 * Логирование отладочной информации
 */
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/events_scanner.log';
    $message = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    if ($data !== null) {
        $message .= print_r($data, true) . "\n";
    }
    file_put_contents($logFile, $message, FILE_APPEND);
}

/**
 * Поиск событий в проекте
 */
function findEventsInProject($appPath, $vendorPath = null): array {
    $events = [];

    if (!is_dir($appPath)) {
        debugLog("Application path is not a directory", ['appPath' => $appPath]);
        return $events;
    }

    scanDirectory($appPath, $events, 'Application');

    if ($vendorPath && is_dir($vendorPath)) {
        scanDirectory($vendorPath, $events, 'Vendor');
    }

    return $events;
}

/**
 * Сканирование директории
 */
function scanDirectory($path, &$events, $source) {
    $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directory);
    $files = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

    foreach ($files as $file) {
        if (!isset($file[0]) || !is_string($file[0])) {
            continue;
        }

        $filePath = $file[0];
        processFile($filePath, $events, $source);
    }
}

/**
 * Обработка PHP файла
 */
function processFile($filePath, &$events, $source) {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        debugLog("Failed to read file", ['file' => $filePath]);
        return;
    }

    $relativePath = str_replace([__DIR__], ['.'], $filePath);
    $className = getClassNameFromContent($content);

    findEventConstants($content, $className, $relativePath, $source, $events);
    findDynamicEvents($content, $className, $relativePath, $source, $events);
}

/**
 * Извлечение имени класса из содержимого файла
 */
/**
 * Извлечение полного имени класса (с namespace) из содержимого файла PHP
 */
function getClassNameFromContent(string $content): string {
    $tokens = token_get_all($content);
    $namespace = $class = '';
    $namespaceFound = false;
    $classFound = false;

    foreach ($tokens as $token) {
        if ($token[0] === T_NAMESPACE && !$namespaceFound) {
            $namespaceFound = true;
            $namespace = '';
            continue;
        }

        if ($namespaceFound && !$classFound) {
            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR])) {
                $namespace .= $token[1];
            } elseif ($token === ';' || $token === '{') {
                $namespaceFound = false;
            }
        }

        if ($token[0] === T_CLASS && !$classFound) {
            $classFound = true;
            continue;
        }

        if ($classFound && $token[0] === T_STRING) {
            $class = $token[1];
            break;
        }
    }

    return ($namespace ? $namespace . '\\' : '') . ($class ?: 'UnknownClass');
}

/**
 * Поиск констант событий
 */
function findEventConstants($content, $className, $filePath, $source, &$events) {
    if (preg_match_all('/const\s+(EVENT_[A-Z_]+)\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (isset($match[1], $match[2])) {
                $events[] = [
                    'type' => 'constant',
                    'name' => $match[1],
                    'value' => $match[2],
                    'class' => $className,
                    'file' => $filePath,
                    'source' => $source,
                    'icon' => '🔹'
                ];
            }
        }
    }
}

/**
 * Поиск динамических событий
 */
function findDynamicEvents($content, $className, $filePath, $source, &$events) {
    if (preg_match_all('/\$this->on\(\s*[\'"]([^\'"]+)[\'"]\s*,/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (isset($match[1])) {
                $events[] = [
                    'type' => 'dynamic',
                    'name' => $match[1],
                    'value' => $match[1],
                    'class' => $className,
                    'file' => $filePath,
                    'source' => $source,
                    'icon' => '🔸'
                ];
            }
        }
    }
}

/**
 * Генерация HTML отчета
 */
function generateHtmlReport(array $events): string {
    $count = count($events);
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events Scanner</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; line-height: 1.6; color: #333; max-width: 1200px; margin: 0 auto; padding: 20px; }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .summary { background-color: #e8f4fc; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .event-card { background: white; border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin-bottom: 15px; }
        .event-name { font-weight: bold; color: #2980b9; font-size: 1.1em; }
        .event-type { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 0.8em; margin-left: 10px; }
        .constant { background-color: #d4edda; color: #155724; }
        .dynamic { background-color: #fff3cd; color: #856404; }
        .event-class { color: #6c757d; font-family: monospace; margin: 5px 0; }
        .event-file { font-size: 0.9em; color: #6c757d; font-family: monospace; word-break: break-all; }
        .search-box { margin: 20px 0; padding: 10px; width: 100%; box-sizing: border-box; border: 2px solid #ddd; border-radius: 5px; }
        .no-results { text-align: center; padding: 20px; color: #6c757d; }
        .source-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; background-color: #e2e3e5; margin-left: 8px; }
    </style>
</head>
<body>
    <h1>Events Scanner</h1>
    <div class="summary">Found <strong>{$count}</strong> events in the project</div>
    <input type="text" class="search-box" placeholder="Search events..." id="searchInput" onkeyup="searchEvents()">
    <div id="eventsContainer">
HTML;

    foreach ($events as $event) {
        $searchable = implode(' ', array_map('htmlspecialchars', [
            $event['name'] ?? '',
            $event['value'] ?? '',
            $event['class'] ?? '',
            $event['file'] ?? '',
            $event['source'] ?? ''
        ]));

        $html .= sprintf(
            '<div class="event-card" data-searchable="%s">
                <div><span class="event-name">%s %s</span><span class="event-type %s">%s</span><span class="source-badge">%s</span></div>
                <div class="event-class">Class: %s</div>
                <div class="event-file">File: %s</div>
                <div><strong>Value:</strong> %s</div>
            </div>',
            $searchable,
            htmlspecialchars($event['icon'] ?? ''),
            htmlspecialchars($event['name'] ?? ''),
            htmlspecialchars($event['type'] ?? ''),
            htmlspecialchars($event['type'] ?? ''),
            htmlspecialchars($event['source'] ?? ''),
            htmlspecialchars($event['class'] ?? ''),
            htmlspecialchars($event['file'] ?? ''),
            htmlspecialchars($event['value'] ?? '')
        );
    }

    $html .= <<<'HTML'
    </div>
    <script>
        function searchEvents() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const events = document.querySelectorAll('.event-card');
            let visibleCount = 0;
            
            events.forEach(event => {
                const text = event.getAttribute('data-searchable').toLowerCase();
                if (text.includes(filter)) {
                    event.style.display = '';
                    visibleCount++;
                } else {
                    event.style.display = 'none';
                }
            });
            
            const container = document.getElementById('eventsContainer');
            let noResults = document.getElementById('noResults');
            
            if (visibleCount === 0 && !noResults) {
                noResults = document.createElement('div');
                noResults.id = 'noResults';
                noResults.className = 'no-results';
                noResults.textContent = 'No events found matching your search.';
                container.appendChild(noResults);
            } else if (noResults && visibleCount > 0) {
                noResults.remove();
            }
        }
    </script>
</body>
</html>
HTML;

    return $html;
}

// Основное выполнение
try {
    debugLog("Starting event scanner");

    // Получаем аргументы командной строки
    $options = getopt('a:v:o:', ['app:', 'vendor:', 'output:']);

    $appPath = $options['a'] ?? $options['app'] ?? null;
    $vendorPath = $options['v'] ?? $options['vendor'] ?? null;
    $outputFile = $options['o'] ?? $options['output'] ?? __DIR__ . '/events_report.html';

    if (!$appPath) {
        throw new Exception("Application path is required. Usage: php scanner.php -a /path/to/app [-v /path/to/vendor] [-o output.html]");
    }

    $allEvents = findEventsInProject($appPath, $vendorPath);

    $html = generateHtmlReport($allEvents);

    if (file_put_contents($outputFile, $html)) {
        echo "HTML report generated: $outputFile\n";
        echo "Found " . count($allEvents) . " events\n";
    } else {
        throw new Exception("Failed to write HTML report to $outputFile");
    }

    debugLog("Successfully completed", ['events_count' => count($allEvents)]);
} catch (Exception $e) {
    debugLog("Fatal error", ['error' => $e->getMessage()]);
    echo "Error: " . $e->getMessage() . "\n";
    echo "Check log file: " . __DIR__ . '/events_scanner.log' . "\n";
    exit(1);
}
