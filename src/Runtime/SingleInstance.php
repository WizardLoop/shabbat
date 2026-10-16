<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

// SPDX-License-Identifier: AGPL-3.0-only

final class SingleInstance
{
    /** @var resource|null */
    private $handle = null;

    private function __construct()
    {
    }

    public static function acquire(string $path): self
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Unable to create runtime directory.');
        }
        $instance = new self();
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the shared writer lock.');
        }
        $instance->handle = $handle;
        if (!flock($instance->handle, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another bot or maintenance operation owns this runtime directory.');
        }
        ftruncate($instance->handle, 0);
        fwrite($instance->handle, (string) getmypid());
        return $instance;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
