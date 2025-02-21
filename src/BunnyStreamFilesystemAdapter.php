<?php

namespace Riajul\LaravelBunnyStream;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Riajul\LaravelBunnyStream\Exceptions\InvalidArgumentException;
use Riajul\LaravelBunnyStream\Exceptions\InvalidFileContentException;
use Riajul\LaravelBunnyStream\Exceptions\UnsupportedFeatureMethodCallException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystemContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use SplFileInfo;
use Throwable;
use ToshY\BunnyNet\Client\BunnyClient;
use ToshY\BunnyNet\StreamAPI;

class BunnyStreamFilesystemAdapter implements CloudFilesystemContract
{
    use Conditionable;
    use Macroable {
        __call as macroCall;
    }

    private $hostname;
    private $library_id;
    private $api_key;

    private $cdnBaseUrl;
    /**
     * @var Guzzle
     */
    private $guzzleClient;
    /**
     * @var BunnyClient
     */
    private $bunnyClient;
    /**
     * @var StreamAPI
     */
    private $bunnyStreamAPI;

    public function __construct(array $config)
    {
        $this->hostname = $config['hostname'];
        $this->library_id = (int) $config['library_id'];
        $this->api_key = $config['api_key'];

        $this->cdnBaseUrl = 'https://' . $this->hostname;

        $this->guzzleClient = new Guzzle();

        $this->bunnyClient = new BunnyClient($this->guzzleClient);

        $this->bunnyStreamAPI = new StreamAPI($this->api_key, $this->bunnyClient);
    }

    private function normalizePathSlashes($path)
    {
        if (is_null($path)) {
            return null;
        }
        if (Str::startsWith($path, '/')) {
            $path = Str::after($path, '/');
        }
        if (Str::endsWith($path, '/')) {
            $path = Str::beforeLast($path, '/');
        }
        if ($path == '/') {
            return null;
        }
        return $path;
    }

    private function stripFilenameFromPath($path)
    {
        if (is_null($path)) {
            return null;
        }
        if (Str::contains(basename($path), '.')) {
            // Most probably a file path.
            return Str::beforeLast($path, '/');
        }
        return $path;
    }

    private function createCdnRequest($path, string $method = 'GET', array $headers = [], $body = null): Request
    {
        return new Request(
            $method,
            "$this->cdnBaseUrl/$path",
            array_merge([
                'Referer' => $this->cdnBaseUrl,
                'Accept' => '*/*',
                'AccessKey' => $this->api_key,
            ], $headers),
            $body
        );
    }

    public function getVideo($videoId): array
    {
        return $this->bunnyStreamAPI->getVideo($this->library_id, $videoId)->getContents();
    }

    private function extractVideoIdFromPath($path)
    {
        $path = $this->normalizePathSlashes($path);

        if (Str::endsWith($path, ['video.m3u8', '.ts'])) {
            // When path is like
            // - {collectionId}/{videoId}/360p/video.m3u8
            // - {videoId}/360p/video0.ts
            $path = Str::beforeLast(Str::beforeLast($path, '/'), '/');
        }

        if (Str::contains($path, '/seek/') && Str::contains($path, '.')) {
            // When path is like
            // - {collectionId}/{videoId}/seek/_0.jpg
            // - {videoId}/seek/anything.file
            $path = Str::beforeLast(Str::beforeLast($path, '/'), '/');
        }

        if (Str::contains(basename($path), '.')) {
            // When path is like
            // - {collectionId}/{videoId}/playlist.m3u8
            // - {videoId}/playlist.m3u8
            $path = Str::beforeLast($path, '/');
        }

        // At this point path is normalized like this:
        // - {collectionId}/{videoId}
        // - user1/personal/something/{videoId} Note: user1/personal/something = can be collection name.
        // - {videoId}
        if (Str::contains($path, '/')) {
            $path = Str::afterLast($path, '/');
        }
        return $path;
    }

    private function toUsableFilePathForCdn($path, bool $hls = true, bool $preferLowResolution = false): string
    {
        $videoId = $this->extractVideoIdFromPath($path);
        if (Str::contains(basename($path), '.') || basename($path) == 'original') {
            // Trying to access a file directly, eg:
            // - {videoId}/playlist.m3u8
            // - {videoId}/play_720p.mp4
            // - {videoId}/original
            // - {videoId}/thumbnail.jpg
            // - {collectionId}/{videoId}/thumbnail.jpg

            return "$videoId/" . Str::afterLast($path, "$videoId/");
        }

        if ($hls) {
            return "$videoId/playlist.m3u8";
        }

        $video = $this->getVideo($videoId);
        $availableResolutions = explode(',', $video['availableResolutions']);
        $decidedResolution = $preferLowResolution ? $availableResolutions[0] : Arr::last($availableResolutions);

        return "$videoId/play_$decidedResolution.mp4";
    }

    public function url($path): string
    {
        $path = $this->toUsableFilePathForCdn($path);
        return "$this->cdnBaseUrl/$path";
    }

    public function path($path): string
    {
        return $this->extractVideoIdFromPath($path);
    }

    public function exists($path): bool
    {
        try {
            $videoId = $this->extractVideoIdFromPath($path);
            return !!@$this->getVideo($videoId)['guid'];
        } catch (Throwable $e) {
            return false;
        }
    }

    private function getContentsFromCdnUrl($path)
    {
        try {
            return $this->guzzleClient->get("$this->cdnBaseUrl/$path", [
                'headers' => [
                    'Referer' => $this->cdnBaseUrl,
                    'Accept' => '*/*',
                    'AccessKey' => $this->api_key,
                ]
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            try {
                return $this->guzzleClient->get("$this->cdnBaseUrl/$path", [
                    'headers' => [
                        'Referer' => 'https://iframe.mediadelivery.net',
                        'Accept' => '*/*',
                        'AccessKey' => $this->api_key,
                    ]
                ])->getBody()->getContents();
            } catch (GuzzleException $e) {
                return null;
            }
        }
    }

    public function get($path): ?string
    {
        $path = $this->toUsableFilePathForCdn($path, false);
        return $this->getContentsFromCdnUrl($path);
    }

    public function getHls($path): ?string
    {
        $videoId = $this->extractVideoIdFromPath($path);
        return $this->getContentsFromCdnUrl("$videoId/playlist.m3u8");
    }

    public function getOriginal($path): ?string
    {
        $videoId = $this->extractVideoIdFromPath($path);
        return $this->getContentsFromCdnUrl("$videoId/original");
    }

    public function getMp4($path, $quality): ?string
    {
        $videoId = $this->extractVideoIdFromPath($path);
        if (preg_match("/[0-9]+p/", $quality)) {
            return $this->getContentsFromCdnUrl("$videoId/play_$quality.mp4");
        }

        $video = $this->getVideo($videoId);

        $resolutions = Arr::sort(
            explode(',', $video['availableResolutions']),
            function ($resolution) {
                return (int) $resolution;
            }
        );

        $resolution = null;
        if ($quality == 'low' || $quality == 'lowest') {
            $resolution = $resolutions[0];
        }
        if ($quality == 'mid' || $quality == 'medium') {
            $resolution = $resolutions[round((count($resolutions) / 2) - 1)];
        }
        if ($quality == 'high' || $quality == 'highest') {
            $resolution = $resolutions[count($resolutions) - 1];
        }

        if ($resolution) {
            $quality = $resolution;
        }

        return $this->getContentsFromCdnUrl("$videoId/play_$quality.mp4");
    }

    public function getMp4Low($path)
    {
        return $this->getMp4($path, 'low');
    }

    public function getMp4Medium($path)
    {
        return $this->getMp4($path, 'medium');
    }

    public function getMp4High($path)
    {
        return $this->getMp4($path, 'high');
    }

    public function readStream($path)
    {
        $path = $this->toUsableFilePathForCdn($path, false);
        return $this->guzzleClient->send(
            $this->createCdnRequest($path),
            ['stream' => true]
        )->getBody()->detach();
    }

    public function getStream($path)
    {
        $path = $this->toUsableFilePathForCdn($path, false);
        return $this->guzzleClient->send(
            $this->createCdnRequest($path),
            ['stream' => true]
        )->getBody();
    }

    public function put($path, $contents, $options = [])
    {
        $name = basename($path);
        if (!Str::contains($path, '.')) {
            $name = null;
        }

        return $this->putFileAs($path, $contents, $name, $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        if (is_null($file) || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        $name = basename($path);
        if (!Str::contains($path, '.')) {
            $name = null;
        }

        return $this->putFileAs($path, $file, $name, $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        // Filename is generated, So we don't use passed filename.
        $path = $this->stripFilenameFromPath($path);
        // Fix inconsistencies.
        $path = $this->normalizePathSlashes($path);
        $collection = null;
        if (!empty($path)) {
            // Find existing collection.
            $collection = $this->findDirectoryCollection($path);

            // If not found create one.
            if (empty($collection)) {
                $collection = $this->bunnyStreamAPI->createCollection($this->library_id, [
                    'name' => $path,
                ])->getContents();
            }
        }

        $video = $this->bunnyStreamAPI->createVideo($this->library_id, array_merge(
            [
                'title' => $name ?? 'default',
            ],
            $collection ? [
                'collectionId' => $collection['guid'],
            ] : []
        ))->getContents();

        $videoId = $video['guid'];

        $stream = null;
        $resource = null;
        if ($file instanceof StreamInterface) {
            $stream = $file;
        } else if (is_resource($file)) {
            $resource = $file;
        } if ($file instanceof SplFileInfo) {
            $resource = fopen($file->getRealPath(), 'r');
        } else if (is_string($file) && file_exists($file)) {
            $resource = fopen(realpath($file), 'r');
        }

        $status = $this->bunnyStreamAPI->uploadVideo(
            $this->library_id,
            $videoId,
                $stream ?? $resource ?? $file
        )->getStatusCode();

        if ($resource) {
            try {
                // close resource.
                fclose($resource);
            } catch (Throwable $e) {}
        }

        if ($status >= 200 && $status < 300) {
            if ($collection) {
                return $collection['name'] . "/$videoId";
            }
            return $videoId;
        }

        return false;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function getVisibility($path)
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function setVisibility($path, $visibility)
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function prepend($path, $data)
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function append($path, $data)
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function delete($paths)
    {
        if (is_array($paths)) {
            foreach ($paths as $path) {
                $this->delete($path);
            }
            return;
        }

        $videoId = $this->extractVideoIdFromPath($paths);
        $this->bunnyStreamAPI->deleteVideo($this->library_id, $videoId);
    }

    public function copy($from, $to)
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function move($from, $to)
    {
        throw new UnsupportedFeatureMethodCallException('Unsupported method call.');
    }

    public function size($path)
    {
        $videoId = $this->extractVideoIdFromPath($path);
        return $this->getVideo($videoId)['storageSize'];
    }

    public function lastModified($path)
    {
        $videoId = $this->extractVideoIdFromPath($path);
        return strtotime($this->getVideo($videoId)['dateUploaded']);
    }

    public function files($directory = null, $recursive = false): array
    {
        return $this->allFiles($directory);
    }

    public function allFiles($directory = null): array
    {
        $directory = $this->normalizePathSlashes($directory);
        $collectionId = $this->findDirectoryCollectionId($directory);
        $videos = $this->allVideos($collectionId);
        return array_map(
            function ($video) {
                return $video['guid'];
            },
            $videos
        );
    }

    public function directories($directory = null, $recursive = false): array
    {
        return $this->allDirectories($directory);
    }

    public function allDirectories($directory = null): array
    {
        $directory = $this->normalizePathSlashes($directory);
        $directories = array_map(
            function ($collection) {
                return $collection['name'];
            },
            $this->allCollections()
        );

        if (!empty($directory)) {
            $directories = array_filter(
                $directories,
                function ($item) use ($directory) {
                    return $item == $directory || strpos($item, "$directory/") === 0;
                }
            );
        }

        return $directories;
    }

    private function findDirectoryCollection($path)
    {
        if (empty($directory)) {
            return null;
        }
        $existingCollections = $this->bunnyStreamAPI->listCollections(
            $this->library_id,
            [
                'search' => $path,
                'itemsPerPage' => 1000,
            ]
        )->getContents()['items'];
        foreach ($existingCollections as $existingCollection) {
            if ($existingCollection['name'] === $path) {
                return $existingCollection;
            }
        }

        return null;
    }

    private function findDirectoryCollectionId($path)
    {
        $collection = $this->findDirectoryCollection($path);
        if ($collection) {
            return $collection['guid'];
        }
        return null;
    }

    public function makeDirectory($path): bool
    {
        $directory = $this->normalizePathSlashes($path);
        if (empty($directory)) {
            throw new InvalidArgumentException('Directory path cannot be empty.');
        }
        $alreadyExistingCollectionId = $this->findDirectoryCollectionId($path);
        if ($alreadyExistingCollectionId) {
            return true;
        }
        return $this->bunnyStreamAPI->createCollection($this->library_id, [
            'name' => $path,
        ])->getStatusCode() === 200;
    }

    public function deleteDirectory($directory): bool
    {
        $directory = $this->normalizePathSlashes($directory);
        $alreadyExistingCollectionId = $this->findDirectoryCollectionId($directory);
        if (!$alreadyExistingCollectionId) {
            return true;
        }

        return $this->bunnyStreamAPI->deleteCollection($this->library_id, $alreadyExistingCollectionId)->getStatusCode() === 200;
    }


    private function allCollections(): array
    {
        $collections = [];
        $page = 1;
        while (true) {
            try {
                $items = $this->bunnyStreamAPI->listCollections(
                    $this->library_id,
                    [
                        'itemsPerPage' => 1000,
                        'page' => $page,
                    ]
                )->getContents()['items'];

                // Increment the page number.
                $page = $page + 1;

                // No more items to load.
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    $collections[] = $item;
                }
            } catch (Throwable $e) {
                break;
            }
        }
        return $collections;
    }

    private function allVideos($collectionId = null): array
    {
        $videos = [];
        $page = 1;
        while (true) {
            try {
                $items = $this->bunnyStreamAPI->listVideos(
                    $this->library_id,
                    array_merge(
                        [
                            'itemsPerPage' => 1000,
                            'page' => $page,
                        ],
                        $collectionId ? ['collection' => $collectionId] : []
                    )
                )->getContents()['items'];

                // Increment the page number.
                $page = $page + 1;

                // No more items to load.
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    $videos[] = $item;
                }
            } catch (Throwable $e) {
                break;
            }
        }
        return $videos;
    }
}