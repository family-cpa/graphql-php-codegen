<?php

namespace GraphQLCodegen\Support;

class StubsFinder
{
    public static function find(): string
    {
        // 1. Попытка через ReflectionClass (работает всегда, если пакет установлен)
        $reflection = new \ReflectionClass(\GraphQLCodegen\Console\App::class);
        $packageDir = dirname($reflection->getFileName(), 2);
        $stubsPath = $packageDir . '/stubs';
        
        if (is_dir($stubsPath)) {
            return $stubsPath;
        }

        // 2. Попытка через Composer InstalledVersions (если доступен)
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $packagePath = \Composer\InstalledVersions::getInstallPath('family-cpa/graphql-codegen');
                if ($packagePath) {
                    $stubsPath = $packagePath . '/stubs';
                    if (is_dir($stubsPath)) {
                        return $stubsPath;
                    }
                }
            } catch (\Throwable $e) {
                // Игнорируем ошибки
            }
        }

        // 3. Fallback: относительный путь от текущего файла
        $fallbackPath = dirname(__DIR__, 2) . '/stubs';
        if (is_dir($fallbackPath)) {
            return $fallbackPath;
        }

        // 4. Последняя попытка: vendor путь (для Laravel)
        $vendorPath = __DIR__ . '/../../../../vendor/family-cpa/graphql-codegen/stubs';
        if (is_dir($vendorPath)) {
            return $vendorPath;
        }

        throw new \RuntimeException('Cannot find stubs directory. Please ensure the package is properly installed.');
    }
}

