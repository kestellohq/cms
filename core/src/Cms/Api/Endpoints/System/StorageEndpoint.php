<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Media\MediaException;
use Kestello\Cms\Media\MediaStorage;

/** Base for /system/storage: where media lives, quotas, moving between disks. @internal */
abstract class StorageEndpoint extends SystemEndpoint
{
    public function tags(): array
    {
        return ['system', 'media'];
    }

    protected function storage(): MediaStorage
    {
        return $this->cms()->container->make(MediaStorage::class);
    }

    protected static function attempt(Closure $work): mixed
    {
        try {
            return $work();
        } catch (MediaException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'forbidden' => ApiProblem::forbidden($e->getMessage()),
                default => ApiProblem::conflict($e->getMessage()),
            };
        }
    }

    /** @return array<string, mixed> */
    protected function overview(): array
    {
        $storage = $this->storage();
        $roles = [];
        foreach ($this->cms()->auth->roles->roles() as $name => $role) {
            if ($name !== 'admin' && in_array('media.upload', $role['capabilities'], true)) {
                $roles[] = ['name' => $name, 'label' => $role['label']];
            }
        }

        return [
            'disks' => $storage->disks(),
            'current' => $storage->current(),
            'move' => $storage->move(),
            'quotas' => (object) $storage->quotas(),
            'roles' => $roles,
            'worker' => !$this->cms()->queue->backend() instanceof \Kestello\Framework\Queue\SyncQueue,
        ];
    }
}
