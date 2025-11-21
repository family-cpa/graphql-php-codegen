<?php

namespace GraphQLCodegen\Support;

use RuntimeException;

class FileWriter
{
    public function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
    }

    public function writeIfChanged(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->ensureDir($dir);

        if (is_file($path)) {
            $old = file_get_contents($path);
            if ($old !== false && $old === $contents) {
                return;
            }
        }

        $result = file_put_contents($path, $contents);
        if ($result === false) {
            throw new RuntimeException("Failed to write file: {$path}");
        }
    }

    public function cleanupDirectory(string $dir, array $keepFiles): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $keepFiles = array_map('basename', $keepFiles);
        $keepFiles = array_flip($keepFiles);

        $files = glob($dir.'/*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $basename = basename($file);
            if (! isset($keepFiles[$basename])) {
                @unlink($file);
            }
        }
    }
}
