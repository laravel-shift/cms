<?php

namespace Statamic\Assets;

use Illuminate\Support\Facades\Cache;
use Statamic\Support\Str;

class AssetContainerContents
{
    protected $container;
    protected $files;
    protected $filteredFiles;
    protected $filteredDirectories;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function all()
    {
        return $this->files = $this->files
            ?? Cache::remember($this->key(), $this->ttl(), function () {
                // Use Flysystem directly because it gives us type, timestamps, dirname
                // and will let us perform more efficient filtering and caching.
                $files = $this->filesystem()->listContents('/', true);

                // If Flysystem 3.x, re-apply sorting and return a backwards compatible result set.
                // See: https://flysystem.thephpleague.com/v2/docs/usage/directory-listings/
                if (! is_array($files)) {
                    return collect($files->sortByPath()->toArray())->keyBy('path')->map(function ($file) {
                        return $this->normalizeFlysystemAttributes($file);
                    });
                }

                return collect($files)->keyBy('path');
            });
    }

    /**
     * Normalize flysystem 3.x `FileAttributes` and `DirectoryAttributes` payloads back to the 1.x array style.
     *
     * @param  mixed  $attributes
     * @return array
     */
    private function normalizeFlysystemAttributes($attributes)
    {
        $pathinfo = pathinfo($attributes['path']);

        $normalized = [
            'type' => $attributes->type(),
            'path' => $attributes->path(),
            'timestamp' => $attributes->lastModified(),
            'dirname' => $pathinfo['dirname'] === '.' ? '' : $pathinfo['dirname'],
            'basename' => $pathinfo['basename'],
            'filename' => $pathinfo['filename'],
        ];

        if (isset($pathinfo['extension'])) {
            $normalized['extension'] = $pathinfo['extension'];
        }

        if ($attributes->type() === 'file') {
            $normalized['size'] = $attributes->fileSize();
        }

        return $normalized;
    }

    /**
     * Normalize flysystem 3.x meta data to match 1.x payloads.
     *
     * @param  string  $path
     * @return array
     */
    private function getNormalizedFlysystemMetadata($path)
    {
        // If Flysystem 1.x, use old method of getting meta data.
        if (class_exists('\League\Flysystem\Util')) {
            try {
                // If the file doesn't exist, this will either throw an exception or return
                // false depending on the adapter and whether or not asserts are enabled.
                $meta = $this->filesystem()->getMetadata($path);

                return $meta ? $meta + \League\Flysystem\Util::pathinfo($path) : false;
            } catch (\Exception $exception) {
                return false;
            }
        }

        if (! $this->filesystem()->has($path)) {
            return false;
        }

        $type = $this->filesystem()->directoryExists($path)
            ? 'dir'
            : 'file';

        $pathinfo = pathinfo($path);

        $normalized = [
            'type' => $type,
            'path' => $path,
            'timestamp' => $this->filesystem()->lastModified($path),
            'dirname' => $pathinfo['dirname'] === '.' ? '' : $pathinfo['dirname'],
            'basename' => $pathinfo['basename'],
            'filename' => $pathinfo['filename'],
        ];

        if (isset($pathinfo['extension'])) {
            $normalized['extension'] = $pathinfo['extension'];
        }

        if ($type === 'file') {
            $normalized['size'] = $this->filesystem()->fileSize($path);
        }

        return $normalized;
    }

    public function cached()
    {
        return Cache::get($this->key());
    }

    public function files()
    {
        return $this->all()->where('type', 'file');
    }

    public function directories()
    {
        return $this->all()->where('type', 'dir');
    }

    public function filteredFilesIn($folder, $recursive)
    {
        if (isset($this->filteredFiles[$key = $folder.($recursive ? '-recursive' : '')])) {
            return $this->filteredFiles[$key];
        }

        $files = $this->files();

        // Filter by folder and recursiveness. But don't bother if we're
        // requesting the root recursively as it's already that way.
        if ($folder === '/' && $recursive) {
            //
        } else {
            $files = $files->filter(function ($file) use ($folder, $recursive) {
                $dir = $file['dirname'] ?: '/';

                return $recursive ? Str::startsWith($dir, $folder) : $dir == $folder;
            });
        }

        // Get rid of files we never want to show up.
        $files = $files->reject(function ($file, $path) {
            return Str::startsWith($path, '.meta/')
                || Str::contains($path, '/.meta/')
                || Str::endsWith($path, ['.DS_Store', '.gitkeep', '.gitignore']);
        });

        return $this->filteredFiles[$key] = $files;
    }

    public function filteredDirectoriesIn($folder, $recursive)
    {
        if (isset($this->filteredDirectories[$key = $folder.($recursive ? '-recursive' : '')])) {
            return $this->filteredDirectories[$key];
        }

        $files = $this->directories();

        // Filter by folder and recursiveness. But don't bother if we're
        // requesting the root recursively as it's already that way.
        if ($folder === '/' && $recursive) {
            //
        } else {
            $files = $files->filter(function ($file) use ($folder, $recursive) {
                $dir = $file['dirname'] ?: '/';

                return $recursive ? Str::startsWith($dir, $folder) : $dir == $folder;
            });
        }

        $files = $files->reject(function ($file) {
            return $file['basename'] == '.meta';
        });

        return $this->filteredDirectories[$key] = $files;
    }

    private function filesystem()
    {
        return $this->container->disk()->filesystem()->getDriver();
    }

    public function save()
    {
        Cache::put($this->key(), $this->all(), $this->ttl());
    }

    public function forget($path)
    {
        $this->files = $this->all()->forget($path);

        $this->filteredFiles = null;
        $this->filteredDirectories = null;

        return $this;
    }

    public function add($path)
    {
        if (! $metadata = $this->getNormalizedFlysystemMetadata($path)) {
            return $this;
        }

        // Add parent directories
        if (($dir = dirname($path)) !== '.') {
            $this->add($dir);
        }

        $this->all()->put($path, $metadata);

        $this->filteredFiles = null;
        $this->filteredDirectories = null;

        return $this;
    }

    private function key()
    {
        return 'asset-list-contents-'.$this->container->handle();
    }

    private function ttl()
    {
        return config('statamic.stache.watcher') ? 0 : null;
    }
}
