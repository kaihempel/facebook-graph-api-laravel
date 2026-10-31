<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Kuweh\MetaGraph\Facades\MetaGraph;
use Kuweh\MetaGraph\Media\MediaSource;

it('publishes photos by url without downloading them', function () {
    $fake = MetaGraph::fake(['v26.0/111/photos' => ['id' => 'ph1', 'post_id' => '111_9']]);

    $photo = MetaGraph::asPage($this->page())->photos()->publish(MediaSource::url('https://cdn.example.com/a.jpg'), 'Bildunterschrift');

    expect($photo->id)->toBe('ph1')
        ->and($photo->postId)->toBe('111_9')
        ->and($photo->raw())->toHaveKey('post_id');

    $fake->assertPosted('v26.0/111/photos', fn (array $p, $r) => $p['url'] === 'https://cdn.example.com/a.jpg'
        && $p['caption'] === 'Bildunterschrift'
        && $p['published'] === 'true'
        && $r->files === []);
});

it('uploads local files and uploaded files as multipart', function () {
    $fake = MetaGraph::fake(['v26.0/111/photos' => ['id' => 'ph2']]);
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, 'jpeg');

    $photos = MetaGraph::asPage($this->page())->photos();
    $photos->uploadUnpublished(MediaSource::file($path, 'local.jpg', 'image/jpeg'));
    $upload = UploadedFile::fake()->image('upload.jpg');
    $photos->uploadUnpublished(MediaSource::upload($upload), temporary: true);

    $fake->assertPosted('v26.0/111/photos', fn (array $p, $r) => $r->files === ['source' => 'local.jpg'] && $p['published'] === 'false' && ! isset($p['temporary']));
    $fake->assertPosted('v26.0/111/photos', fn (array $p, $r) => $r->files === ['source' => 'upload.jpg'] && ($p['temporary'] ?? null) === 'true');

    unlink($path);
});
