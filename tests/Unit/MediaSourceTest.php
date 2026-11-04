<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KaiHempel\MetaGraph\Media\MediaSource;

it('describes url sources', function () {
    $source = MediaSource::url('https://cdn.example.com/path/video.mp4');

    expect($source->isRemote())->toBeTrue()
        ->and($source->remoteUrl())->toBe('https://cdn.example.com/path/video.mp4')
        ->and($source->fileName())->toBe('video.mp4')
        ->and($source->mimeType())->toBe('application/octet-stream')
        ->and($source->size())->toBe(0)
        ->and(fn () => $source->openStream())->toThrow(LogicException::class)
        ->and(fn () => $source->read(0, 1))->toThrow(LogicException::class)
        ->and(fn () => MediaSource::url('ftp://x'))->toThrow(InvalidArgumentException::class);
});

it('reads file sources in chunks', function () {
    $path = tempnam(sys_get_temp_dir(), 'media');
    file_put_contents($path, '0123456789');
    $source = MediaSource::file($path);

    expect($source->isRemote())->toBeFalse()
        ->and($source->remoteUrl())->toBeNull()
        ->and($source->size())->toBe(10)
        ->and($source->fileName())->toBe(basename($path))
        ->and($source->mimeType())->toBe('text/plain')
        ->and($source->read(3, 4))->toBe('3456')
        ->and($source->read(8, 10))->toBe('89')
        ->and(fn () => MediaSource::file('/does/not/exist'))->toThrow(InvalidArgumentException::class);

    unlink($path);
});

it('streams disk sources without loading everything', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('videos/clip.mp4', 'abcdefghij');

    $source = MediaSource::disk('s3', 'videos/clip.mp4');

    expect($source->fileName())->toBe('clip.mp4')
        ->and($source->size())->toBe(10)
        ->and($source->mimeType())->toBeString()
        ->and($source->read(5, 3))->toBe('fgh')
        ->and(stream_get_contents($source->openStream()))->toBe('abcdefghij')
        ->and(fn () => MediaSource::disk('s3', 'missing.mp4')->openStream())->toThrow(RuntimeException::class, 'Unable to open [missing.mp4] on disk [s3].');
});

it('reads non seekable streams by skipping', function () {
    $source = new class extends MediaSource
    {
        public function fileName(): string
        {
            return 'pipe';
        }

        public function mimeType(): string
        {
            return 'text/plain';
        }

        public function size(): int
        {
            return 10;
        }

        public function openStream()
        {
            $process = popen('printf 0123456789', 'r');

            return $process === false ? throw new RuntimeException : $process;
        }
    };

    expect($source->read(4, 3))->toBe('456');
});

it('wraps uploaded files and in memory contents', function () {
    $upload = UploadedFile::fake()->createWithContent('bild.jpg', 'jpeg-data');
    $source = MediaSource::upload($upload);
    $contents = MediaSource::contents('raw', 'raw.bin', 'application/x-test');

    expect($source->fileName())->toBe('bild.jpg')
        ->and($source->read(0, 4))->toBe('jpeg')
        ->and($contents->size())->toBe(3)
        ->and($contents->mimeType())->toBe('application/x-test')
        ->and($contents->fileName())->toBe('raw.bin')
        ->and($contents->read(1, 2))->toBe('aw');
});
