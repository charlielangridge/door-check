<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\FileManipulator;

#[Signature('demo:restore-media')]
#[Description('Restore the seven bundled Willowbrook demo images to the configured media disk without reseeding')]
class RestoreDemoMedia extends Command
{
    public function handle(FileManipulator $conversions): int
    {
        $site = Site::query()->where('slug', 'willowbrook-nursery')->where('name', 'Willowbrook Nursery')->first();
        if ($site === null) {
            $this->error('The Willowbrook demo nursery has not been seeded.');

            return self::FAILURE;
        }

        $assets = [
            [$site, 'logo', 'sample-logo.png'],
            [$site->adults()->where('name', 'David Piggington')->sole(), 'photo', 'daddy-pig.webp'],
            [$site->adults()->where('name', 'Mary Piggington')->sole(), 'photo', 'mummy-pig.webp'],
            [$site->adults()->where('name', 'Suzy Mutton')->sole(), 'photo', 'suzy-sheep.webp'],
            [$site->children()->where('name', 'George Piggington')->sole(), 'photo', 'george-pig.webp'],
            [$site->children()->where('name', 'Peppa Piggington')->sole(), 'photo', 'peppa-pig.webp'],
            [$site->children()->where('name', 'Richard Rabbitsworth')->sole(), 'photo', 'richard-rabbit.webp'],
        ];
        $targetDisk = (string) config('media-library.disk_name');
        $storage = Storage::disk($targetDisk);

        foreach ($assets as [$model, $collection, $filename]) {
            $source = base_path('source/'.$filename);
            $media = $model->getMedia($collection)->where('file_name', $filename)->sole();
            if ($media->disk === $targetDisk && $storage->exists($media->getPathRelativeToRoot())) {
                $this->line('Already present: '.$filename);

                continue;
            }

            $stream = fopen($source, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Cannot read bundled demo asset: '.$filename);
            }

            try {
                if (! $storage->put($media->getPathRelativeToRoot(), $stream)) {
                    throw new RuntimeException('Cannot restore bundled demo asset: '.$filename);
                }
            } finally {
                fclose($stream);
            }

            $media->forceFill([
                'disk' => $targetDisk,
                'conversions_disk' => $targetDisk,
                'generated_conversions' => [],
            ])->save();
            $conversions->createDerivedFiles($media);
            $this->info('Restored: '.$filename);
        }

        return self::SUCCESS;
    }
}
