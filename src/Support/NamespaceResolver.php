<?php

namespace GraphQLCodegen\Support;

class NamespaceResolver
{
    private static ?string $projectRoot = null;

    private static function findProjectRoot(): ?string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        // Начинаем с текущей директории или директории скрипта
        $startDir = getcwd() ?: __DIR__;
        $dir = realpath($startDir);

        if (! $dir) {
            return null;
        }

        // Ищем корень проекта по индикаторам
        while ($dir !== dirname($dir)) {
            // Проверяем наличие composer.json или vendor/
            if (file_exists($dir.DIRECTORY_SEPARATOR.'composer.json') ||
                is_dir($dir.DIRECTORY_SEPARATOR.'vendor')) {
                self::$projectRoot = $dir;

                return $dir;
            }

            $dir = dirname($dir);
        }

        // Если не нашли, пробуем найти через vendor путь пакета
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $packagePath = \Composer\InstalledVersions::getInstallPath('family-cpa/graphql-codegen');
                if ($packagePath) {
                    // Идем вверх от vendor/family-cpa/graphql-codegen до корня проекта
                    $vendorDir = dirname(dirname($packagePath));
                    if (is_dir($vendorDir) && basename($vendorDir) === 'vendor') {
                        self::$projectRoot = dirname($vendorDir);

                        return self::$projectRoot;
                    }
                }
            } catch (\Throwable $exception) {
                // Игнорируем ошибки
            }
        }

        return null;
    }

    public static function pathToNamespace(string $path): string
    {
        // Нормализуем путь
        $normalized = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        // Преобразуем в абсолютный путь если нужно
        if (! self::isAbsolutePath($normalized)) {
            $normalized = realpath($normalized) ?: $normalized;
        }

        // Находим корень проекта
        $projectRoot = self::findProjectRoot();

        if ($projectRoot) {
            $projectRoot = realpath($projectRoot);
            $normalized = realpath($normalized) ?: $normalized;

            // Если путь находится внутри проекта, обрезаем до корня проекта
            if ($projectRoot && str_starts_with($normalized, $projectRoot)) {
                $relativePath = substr($normalized, strlen($projectRoot));
                $normalized = ltrim($relativePath, DIRECTORY_SEPARATOR);
            }
        } else {
            // Если корень проекта не найден, убираем только системные части пути
            if (preg_match('/^[A-Z]:/i', $normalized)) {
                $normalized = preg_replace('/^[A-Z]:[\\/]/i', '', $normalized);
            } elseif (str_starts_with($normalized, '/')) {
                $normalized = ltrim($normalized, '/');
            }
        }

        // Разбиваем на части
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $normalized), fn ($part) => $part !== '');

        // Преобразуем каждую часть в PascalCase для namespace
        $namespaceParts = array_map(function ($part) {
            // Убираем расширения файлов если есть
            $part = pathinfo($part, PATHINFO_FILENAME);

            // Преобразуем в PascalCase
            return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
        }, $parts);

        return implode('\\', $namespaceParts);
    }

    private static function isAbsolutePath(string $path): bool
    {
        // Windows: C:\ или \\server
        if (preg_match('/^[A-Z]:[\\/]/i', $path) || str_starts_with($path, '\\\\')) {
            return true;
        }

        // Unix: /path
        if (str_starts_with($path, '/')) {
            return true;
        }

        return false;
    }

    public static function resolveBaseNamespace(string $outputDir): string
    {
        if (empty($outputDir)) {
            return 'GraphQL';
        }

        $namespace = self::pathToNamespace($outputDir);

        // Если namespace пустой (например, путь был только из разделителей), возвращаем дефолтный
        if (empty($namespace)) {
            return 'GraphQL';
        }

        return $namespace;
    }
}
