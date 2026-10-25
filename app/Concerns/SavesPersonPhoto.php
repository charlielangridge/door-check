<?php

namespace App\Concerns;

use App\Models\Adult;
use App\Models\Child;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait SavesPersonPhoto
{
    protected function afterCreate(): void
    {
        $this->savePersonPhoto();
    }

    protected function afterSave(): void
    {
        $this->savePersonPhoto();
    }

    private function savePersonPhoto(): void
    {
        $record = $this->getRecord();
        if (! $record instanceof Adult && ! $record instanceof Child) {
            return;
        }
        $uploads = $this->data['new_photo'] ?? [];
        foreach (is_array($uploads) ? $uploads : [$uploads] as $upload) {
            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }
            $path = $upload->getRealPath();
            if ($path === '') {
                throw ValidationException::withMessages(['new_photo' => 'The uploaded photo could not be read. Please upload it again.']);
            }
            $record->addMedia($path)->preservingOriginal()->usingFileName(Str::uuid().'.'.($upload->guessExtension() ?: 'jpg'))->toMediaCollection('logo', 'local');
        }
        $this->data['new_photo'] = [];
        $record->unsetRelation('media');
    }
}
