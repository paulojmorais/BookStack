<?php

namespace BookStack\Uploads;

use BookStack\Exceptions\FileUploadException;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem as Storage;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\WhitespacePathNormalizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileStorage
{
    public function __construct(
        protected FilesystemManager $fileSystem,
    ) {
    }

    /**
     * @return resource|null
     */
    public function getReadStream(string $path)
    {
        return $this->getStorageDisk()->readStream($this->adjustPathForStorageDisk($path));
    }

    public function getSize(string $path): int
    {
        return $this->getStorageDisk()->size($this->adjustPathForStorageDisk($path));
    }

    public function delete(string $path, bool $removeEmptyDir = false): void
    {
        $storage = $this->getStorageDisk();
        $adjustedPath = $this->adjustPathForStorageDisk($path);
        $dir = dirname($adjustedPath);

        $storage->delete($adjustedPath);
        if ($removeEmptyDir && count($storage->allFiles($dir)) === 0) {
            $storage->deleteDirectory($dir);
        }
    }

    /**
     * @throws FileUploadException
     */
    public function uploadFile(UploadedFile $file, string $subDirectory, string $suffix, string $extension): string
    {
        $storage = $this->getStorageDisk();
        $basePath = trim($subDirectory, '/') . '/';

        $uploadFileName = Str::random(16) . ($suffix ? "-{$suffix}" : '') . ($extension ? ".{$extension}" : '');
        while ($storage->exists($this->adjustPathForStorageDisk($basePath . $uploadFileName))) {
            $uploadFileName = Str::random(3) . $uploadFileName;
        }

        $fileStream = fopen($file->getRealPath(), 'r');
        $filePath = $basePath . $uploadFileName;

        try {
            $storage->writeStream($this->adjustPathForStorageDisk($filePath), $fileStream);
        } catch (Exception $e) {
            Log::error('Error when attempting file upload:' . $e->getMessage());

            throw new FileUploadException(trans('errors.path_not_writable', ['filePath' => $filePath]));
        }

        return $filePath;
    }

    /**
     * Get the storage that will be used for storing files.
     */
    protected function getStorageDisk(): Storage
    {
        return $this->fileSystem->disk($this->getStorageDiskName());
    }

    /**
     * Get the name of the storage disk to use.
     */
    protected function getStorageDiskName(): string
    {
        $storageType = config('filesystems.attachments');

        // Change to our secure-attachment disk if any of the local options
        // are used to prevent escaping that location.
        if ($storageType === 'local' || $storageType === 'local_secure' || $storageType === 'local_secure_restricted') {
            $storageType = 'local_secure_attachments';
        }

        return $storageType;
    }

    /**
     * Change the originally provided path to fit any disk-specific requirements.
     * This also ensures the path is kept to the expected root folders.
     */
    protected function adjustPathForStorageDisk(string $path): string
    {
        $path = (new WhitespacePathNormalizer())->normalizePath(str_replace('uploads/files/', '', $path));

        if ($this->getStorageDiskName() === 'local_secure_attachments') {
            return $path;
        }

        return 'uploads/files/' . $path;
    }
}
