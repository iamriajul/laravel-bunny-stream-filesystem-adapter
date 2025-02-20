<?php

namespace Riajul\LaravelBunnyStream;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class BunnyStreamServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Storage::extend('bunny_stream', function($app, $config) {
            return new BunnyStreamFilesystemAdapter($config);
        });
    }
}