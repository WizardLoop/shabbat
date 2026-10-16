<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Scheduling;

final class InstanceLock
{
    /** @var resource|null */
    private mixed $handle = null;
    public function __construct(string $path)
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create process lock directory.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another bot/maintenance writer already holds the application lock.');
        }
        $this->handle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);
    }
    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
    public function __destruct()
    {
        $this->release();
    }
}
