<img src="https://bunny.net/static/bunnynet-dark-d6a41260b1e4b665cb2dc413e3eb84ca.svg">

# Bunny Stream for Laravel

This is Laravel Filesystem's Driver for simple integration with Laravel.

## Installation
```bash
composer require iamriajul/laravel-bunny-stream-filesystem-adapter
```

## Configuration

This package automatically register the service provider and the storage disk for the driver `bunny_stream`. You can configure the disk in `config/filesystems.php`:

```php
'bunny_stream' => [
    'driver' => 'bunny_stream',
    'hostname' => env('BUNNY_STREAM_HOSTNAME'),
    'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
    'api_key' => env('BUNNY_STREAM_API_KEY'),
],
```

and remember to add the environment variables in your `.env` file:

```dotenv
BUNNY_STREAM_HOSTNAME=your-stream-cdn.b-cdn.net
BUNNY_API_KEY=your-api-key
BUNNY_STREAM_LIBRARY_ID=123456
```


## Usage

```php
$videoId = Storage::disk('bunny_stream')->put('abc.mp4', file_get_contents('abc.mp4'));
// $videoId = guid from bunny.

 // Enable Direct File Access from Bunny to Access m3u8
return response(Storage::disk('bunny_stream')->get("$videoId/playlist.m3u8"));
```

### Extra Methods
| Method                                    | Description                                                                                                                                                                                                |
|-------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| get($path)                                | By default returns playlist.m3u8's content, but you can customize it by adding suffix like "$path/playlist.m3u8", "$path/play_240p.mp4" and more.                                                          |
| getHls($path)                             | Returns playlist.m3u8's content, which would be the main entrypoint for any HLS player.                                                                                                                    |
| getOriginal($path)                        | As the name suggests, it just returns the original file's content user had uploaded initially                                                                                                              |
| getMp4($path, $quality = '240p,360p,etc') | Returns Mp4 file's content, $quality param allows you to customize which file you want, such `240p`, `360p`, `720p`, NOTE: all the quality might not be available depending on the Original file's quality |
| getMp4($path, $quality = 'low,mid,high')  | Not recommended to be called in N+1 situation, as this requires API to resolve what resolution is the low, and what is high, mid, etc.                                                                     |