<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class DigitalBookFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $extension = strtolower($value->getClientOriginalExtension());
        $valid = match ($extension) {
            'pdf' => $value->getMimeType() === 'application/pdf',
            'epub' => $this->validEpub($value),
            default => false,
        };

        if (! $valid) {
            $fail('Upload a valid PDF or EPUB file.');
        }
    }

    private function validEpub(UploadedFile $file): bool
    {
        $archive = new ZipArchive;
        if ($archive->open($file->getPathname(), ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $mime = $archive->statIndex(0);
            $container = $archive->statName('META-INF/container.xml');

            return $mime !== false && $mime['name'] === 'mimetype'
                && $mime['size'] === 20 && $mime['comp_method'] === ZipArchive::CM_STORE
                && $archive->getFromIndex(0, 20) === 'application/epub+zip'
                && $container !== false && $container['size'] > 0;
        } finally {
            $archive->close();
        }
    }
}
