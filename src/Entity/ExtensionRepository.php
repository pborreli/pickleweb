<?php

namespace PickleWeb\Entity;

use Predis\Client;

/**
 * Class ExtensionRepository.
 */
class ExtensionRepository
{
    const EXTENSION_HASH_STORE = 'extensions';
    const EXTENSION2USER_HASH_STORE = 'extension2user';

    /**
     * @var Client
     */
    protected $redicClient;

    /**
     * @param Client $redicClient
     */
    public function __construct(Client $redicClient)
    {
        $this->redicClient = $redicClient;
    }

    /**
     * @param Extension $extension
     * @param User      $extension
     */
    public function persist(Extension $extension, User $user)
    {
        $extensionSerialize = $extension->serialize();
        $this->redicClient->hset(self::EXTENSION_HASH_STORE, $extension->getName(), $extensionSerialize);
        $this->redicClient->hset(self::EXTENSION2USER_HASH_STORE, $extension->getName(), $user->getName());
    }

    /**
     * @param Extension $extension
     */
    public function remove(Extension $extension)
    {
        $id = $extension->getName();
        $this->redicClient->hdel(self::EXTENSION2USER_HASH_STORE, $id);
        $this->redicClient->hdel(self::EXTENSION_HASH_STORE, $id);
    }

    /**
     * @param string $name
     *
     * @return Extension|null
     */
    public function find($name)
    {
        $extensionSerialize = $this->redicClient->hget(self::EXTENSION_HASH_STORE, strtolower(trim($name)));
        if (!$extensionSerialize) {
            return;
        }
        $extension = new Extension();
        $extension->unserialize($extensionSerialize);

        return empty($extension) ? null : $extension;
    }
}
