<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Psr\Log\LoggerInterface;

class PictureUploaderService
{
    private string $uploadsDir;
    private LoggerInterface $logger;

    public function __construct(KernelInterface $kernel, LoggerInterface $logger)
    {
        $this->uploadsDir = $kernel->getProjectDir() . '/public/uploads/pictures/';
        $this->logger = $logger;
    }

    public function upload(UploadedFile $file): ?string
    {
        $fileName = uniqid() . '.' . $file->guessExtension();

        try {
            $file->move($this->uploadsDir, $fileName);
            return '/uploads/pictures/' . $fileName;
        } catch (FileException $e) {
            $this->logger->error('File upload failed: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteFile(?string $filePath): void
    {
        if (!$filePath) {
            return;
        }

        $fullPath = $this->uploadsDir . basename($filePath);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        } else {
            $this->logger->warning('File not found for deletion: ' . $fullPath);
        }
    }
}