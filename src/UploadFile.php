<?php

namespace GraphQLCodegen;

/**
 * Представляет файл для загрузки через GraphQL Upload скаляр.
 * Используется для передачи файлов в мутациях согласно спецификации GraphQL Multipart Request.
 */
class UploadFile
{
    /**
     * @param string $path Путь к файлу на диске
     * @param string|null $filename Имя файла (если не указано, берется из пути)
     * @param string|null $mimeType MIME-тип файла (если не указан, определяется автоматически)
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $filename = null,
        public readonly ?string $mimeType = null
    ) {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        if (! is_readable($path)) {
            throw new \InvalidArgumentException("File is not readable: {$path}");
        }
    }

    /**
     * Получить имя файла
     */
    public function getFilename(): string
    {
        return $this->filename ?? basename($this->path);
    }

    /**
     * Получить MIME-тип файла
     */
    public function getMimeType(): string
    {
        if ($this->mimeType !== null) {
            return $this->mimeType;
        }

        $mimeType = mime_content_type($this->path);
        if ($mimeType === false) {
            return 'application/octet-stream';
        }

        return $mimeType;
    }

    /**
     * Получить содержимое файла
     */
    public function getContents(): string
    {
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read file: {$this->path}");
        }

        return $contents;
    }

    /**
     * Получить размер файла в байтах
     */
    public function getSize(): int
    {
        $size = filesize($this->path);
        if ($size === false) {
            throw new \RuntimeException("Failed to get file size: {$this->path}");
        }

        return $size;
    }
}

