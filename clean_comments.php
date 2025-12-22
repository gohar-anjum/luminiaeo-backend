<?php

function cleanPhpFile($filePath) {
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $result = [];
    $inDocblock = false;
    $docblockBuffer = [];
    $keepDocblock = false;
    
    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        
        if (preg_match('/^\s*\/\*\*/', $line)) {
            $inDocblock = true;
            $docblockBuffer = [$line];
            $keepDocblock = false;
            
            if ($i < count($lines) - 1) {
                $nextLine = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : '';
                if (preg_match('/^\s*(public|protected)\s+function/', $nextLine) || 
                    preg_match('/^\s*function\s+\w+\(/', $nextLine)) {
                    $keepDocblock = true;
                }
            }
            continue;
        }
        
        if ($inDocblock) {
            $docblockBuffer[] = $line;
            if (preg_match('/\*\/\s*$/', $line)) {
                $inDocblock = false;
                if ($keepDocblock) {
                    $docblockContent = implode("\n", $docblockBuffer);
                    if (preg_match('/@(param|return|throws|throws)/', $docblockContent)) {
                        $result[] = $docblockContent;
                    }
                }
                $docblockBuffer = [];
                $keepDocblock = false;
            }
            continue;
        }
        
        $cleaned = $line;
        
        if (preg_match('/\/\//', $cleaned)) {
            $cleaned = preg_replace('/\/\/.*$/', '', $cleaned);
            $cleaned = rtrim($cleaned);
        }
        
        if (preg_match('/\/\*.*?\*\//', $cleaned)) {
            $cleaned = preg_replace('/\/\*.*?\*\//', '', $cleaned);
            $cleaned = rtrim($cleaned);
        }
        
        if ($cleaned !== '' || empty($result) || trim(end($result)) !== '') {
            $result[] = $cleaned;
        }
    }
    
    $cleanedContent = implode("\n", $result);
    $cleanedContent = preg_replace('/\n{3,}/', "\n\n", $cleanedContent);
    
    if ($cleanedContent !== $content) {
        file_put_contents($filePath, $cleanedContent);
        return true;
    }
    
    return false;
}

function cleanPythonFile($filePath) {
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $result = [];
    $inDocstring = false;
    $docstringBuffer = [];
    $keepDocstring = false;
    
    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        
        if (preg_match('/^""".*?"""\s*$/', $trimmed) || preg_match("/^'''.*?'''\s*$/", $trimmed)) {
            if ($i > 0 && preg_match('/^\s*def\s+\w+\(/', $lines[$i - 1])) {
                $result[] = $line;
            }
            continue;
        }
        
        if (preg_match('/^"""\s*$/', $trimmed) || preg_match("/^'''\s*$/", $trimmed)) {
            $inDocstring = true;
            $docstringBuffer = [$line];
            $keepDocstring = ($i > 0 && preg_match('/^\s*def\s+\w+\(/', $lines[$i - 1]));
            continue;
        }
        
        if ($inDocstring) {
            $docstringBuffer[] = $line;
            if (preg_match('/"""\s*$/', $trimmed) || preg_match("/'''\s*$/", $trimmed)) {
                $inDocstring = false;
                if ($keepDocstring) {
                    $result[] = implode("\n", $docstringBuffer);
                }
                $docstringBuffer = [];
                $keepDocstring = false;
            }
            continue;
        }
        
        $cleaned = $line;
        
        if (preg_match('/#/', $cleaned)) {
            $hashPos = strpos($cleaned, '#');
            $beforeHash = substr($cleaned, 0, $hashPos);
            $inString = false;
            $quoteChar = null;
            
            for ($j = 0; $j < strlen($beforeHash); $j++) {
                $char = $beforeHash[$j];
                if ($char === '"' || $char === "'") {
                    if ($j === 0 || $beforeHash[$j - 1] !== '\\') {
                        if (!$inString) {
                            $inString = true;
                            $quoteChar = $char;
                        } elseif ($char === $quoteChar) {
                            $inString = false;
                            $quoteChar = null;
                        }
                    }
                }
            }
            
            if (!$inString) {
                $cleaned = rtrim($beforeHash);
            }
        }
        
        if ($cleaned !== '' || empty($result) || trim(end($result)) !== '') {
            $result[] = $cleaned;
        }
    }
    
    $cleanedContent = implode("\n", $result);
    $cleanedContent = preg_replace('/\n{3,}/', "\n\n", $cleanedContent);
    
    if ($cleanedContent !== $content) {
        file_put_contents($filePath, $cleanedContent);
        return true;
    }
    
    return false;
}

$baseDir = __DIR__;
$directories = ['app', 'routes', 'config', 'database', 'tests', 'keyword-clustering-service', 'pbn-detector', 'resources/js'];
$excludeDirs = ['vendor', 'node_modules', 'storage/framework', 'bootstrap/cache'];

$processed = 0;
foreach ($directories as $dir) {
    $dirPath = $baseDir . '/' . $dir;
    if (!is_dir($dirPath)) continue;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filePath = $file->getRealPath();
            $relativePath = str_replace($baseDir . '/', '', $filePath);
            
            if (in_array(basename(dirname($filePath)), $excludeDirs)) {
                continue;
            }
            
            if (strpos($relativePath, 'vendor/') !== false || 
                strpos($relativePath, 'node_modules/') !== false ||
                strpos($relativePath, 'storage/framework/') !== false ||
                strpos($relativePath, 'bootstrap/cache/') !== false) {
                continue;
            }
            
            $ext = $file->getExtension();
            if ($ext === 'php') {
                if (cleanPhpFile($filePath)) {
                    $processed++;
                    echo "Processed: $relativePath\n";
                }
            } elseif ($ext === 'py') {
                if (cleanPythonFile($filePath)) {
                    $processed++;
                    echo "Processed: $relativePath\n";
                }
            }
        }
    }
}

echo "\nTotal files processed: $processed\n";

