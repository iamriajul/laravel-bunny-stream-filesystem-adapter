<?php

namespace Riajul\LaravelBunnyStream;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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

    private function createCdnRequest($path, string $method = 'GET', array $headers = [], $body = null): Request
    {
        return new Request(
            $method,
            "$this->cdnBaseUrl/$path",
            array_merge([
                'Accept' => '*/*',
                'AccessKey' => $this->api_key,
            ], $headers),
            $body
        );
    }

    public function getVideo($videoId): array
    {
        $video = json_decode(
            $this->bunnyStreamAPI->getVideo($this->library_id, $videoId)
                ->getBody()
                ->getContents(),
            true
        );
        return $video;
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
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get($path): ?string
    {
        $path = $this->toUsableFilePathForCdn($path, false);
        return $this->guzzleClient->get("$this->cdnBaseUrl/$path")->getContents();
    }

    public function readStream($path)
    {
        $path = $this->toUsableFilePathForCdn($path, false);
        return $this->guzzleClient->send(
            $this->createCdnRequest($path),
            ['stream' => true]
        )->getBody()->detach();
    }

    public function put($path, $contents, $options = [])
    {
        return $this->putFileAs($path, $contents, "doesn't matter", $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        if (is_null($file) || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        return $this->putFileAs($path, $file, "doesn't matter", $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        $path = $this->normalizePathSlashes($path);
        $collectionId = null;
        if (!empty($path)) {
            $collectionId = json_decode(
                $this->bunnyStreamAPI->createCollection($this->library_id, [
                    'name' => $path,
                ])->getContents(),
                true
            )['guid'];
        }

        $video = json_decode(
            $this->bunnyStreamAPI->createVideo($this->library_id, array_merge(
                [
                    'title' => $name,
                ],
                $collectionId ? [
                    'collectionId' => $collectionId,
                ] : []
            ))->getContents(),
            true
        );

        $videoId = $video['guid'];

        $filePath = null;
        if ($file instanceof SplFileInfo) {
            $filePath = $file->getRealPath();
        } else if (is_string($file) && file_exists($file)) {
            $filePath = realpath($file);
        }

        $resource = null;
        if (!$filePath) {
            if (is_resource($file)) {
                $resource = $file;
            } else if (is_string($file)) {
                $tmpFile = tmpfile();
                fwrite($tmpFile, $file);
                rewind($tmpFile);
                $resource = $tmpFile;
            } else {
                throw new InvalidFileContentException('Invalid file content type');
            }
        } else {
            $resource = fopen($filePath, 'r');
        }

        $this->bunnyStreamAPI->uploadVideo($this->library_id, $videoId, $resource)->getContents();

        // close resource.
        fclose($resource);

        if ($collectionId) {
            return "$collectionId/$videoId";
        }
        return $videoId;
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
        $existingCollections = json_decode(
            $this->bunnyStreamAPI->listCollections(
                $this->library_id,
                [
                    'search' => $path,
                    'itemsPerPage' => 1000,
                ]
            )->getContents(),
            true
        )['items'];
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
                $items = json_decode(
                    $this->bunnyStreamAPI->listCollections(
                        $this->library_id,
                        [
                            'itemsPerPage' => 1000,
                            'page' => $page,
                        ]
                    )->getContents(),
                    true
                )['items'];

                // Increment the page number.
                $page = $page + 1;

                // No more items to load.
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    $collections[] = $item;
                }
            } catch (\Throwable $e) {
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
                $items = json_decode(
                    $this->bunnyStreamAPI->listVideos(
                        $this->library_id,
                        array_merge(
                            [
                                'itemsPerPage' => 1000,
                                'page' => $page,
                            ],
                            $collectionId ? ['collection' => $collectionId] : []
                        )
                    )->getContents(),
                    true
                )['items'];

                // Increment the page number.
                $page = $page + 1;

                // No more items to load.
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    $videos[] = $item;
                }
            } catch (\Throwable $e) {
                break;
            }
        }
        return $videos;
    }
}