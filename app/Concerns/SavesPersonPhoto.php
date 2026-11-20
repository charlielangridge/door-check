<?php

namespace App\Concerns;

use App\Models\Adult;
use App\Models\Child;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;

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
            try {
                $record->addMediaFromDisk(FileUploadConfiguration::path($upload->getFilename(), false), FileUploadConfiguration::disk())
                    ->preservingOriginal()
                    ->usingFileName(Str::uuid().'.'.($upload->guessExtension() ?: 'jpg'))
                    ->toMediaCollection('photo');
            } catch (FileDoesNotExist) {
                throw ValidationException::withMessages(['data.new_photo' => 'The uploaded photo could not be read. Please upload it again.']);
            }
        }
        $this->data['new_photo'] = [];
        $record->unsetRelation('media');
    }
}
