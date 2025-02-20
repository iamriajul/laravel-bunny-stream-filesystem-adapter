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