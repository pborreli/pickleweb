<?php

namespace PickleWeb\Controller;

use PickleWeb\Entity\ExtensionRepository as ExtensionRepository;
use PickleWeb\Entity\Extension as Extension;
use Composer\IO\BufferIO as BufferIO;
use Composer\Package\Version\VersionParser as VersionParser;
use PickleWeb\Rest as Rest;

/**
 * Class GithubController.
 */
class GithubController extends ControllerAbstract
{
    protected $user;

    /**
     * @param string $name
     *
     * @return bool
     */
    protected function findRegisteredExension($name)
    {
        $redis = $this->app->container->get('redis.client');
        list($vendorName, $repoName) = explode('/', $name);
        $extensionRepository = new ExtensionRepository($redis);
        $extension = $extensionRepository->find($name);
        if (!$extension) {
            $this->app->jsonResponse(
            [
                'status' => 'error',
                'message' => 'extension not found',
            ],
            404
            );

            return false;
        }

        return true;
    }

    /**
     * valid Payload using API key.
     */
    protected function validPayload($vendor, $repository)
    {
        $hubSignature = $this->app->request()->headers()->get('X-Hub-Signature');

        if (!$hubSignature) {
            die('come back with what I need');
        }

        $redis = $this->app->container->get('redis.client');
        $key = $redis->hget('extension_apikey', $vendor.'/'.$repository);

        list($algo, $hash) = explode('=', $hubSignature, 2);

        $payload = file_get_contents('php://input');
        $payloadHash = hash_hmac($algo, $payload, $key);

        /* not from github, no need to be nice */
        if ($hash !== $payloadHash) {
            die('come back with what I need');
        }
    }

    /**
     * @param string $username
     *
     * Hook for github hooks. Only release and tag are supported.
     */
    public function hookAction($vendor, $repository)
    {
        $this->validPayload($vendor, $repository);

        $payloadPost = $this->app->request->getBody();

        $payload = json_decode($payloadPost);

        if (!$payload) {
            $this->app->jsonResponse([
                'status' => 'error',
                'message' => 'invalid Payload',
            ],
            200);

            return;
        }

        if (!($payload->ref_type == 'tag' || $payload->ref_type == 'release')) {
            $this->app->jsonResponse(
            [
                'status' => 'error',
                'message' => 'Only tag/release hooks are supported',
            ],
            200
            );

            return;
        }

        $extensionName = $payload->repository->full_name;
        $tag = $payload->ref;
        $repository = $payload->repository->git_url;

        $ownerId    = $payload->repository->owner->id;
        $userRepository = $this->app->container->get('user.repository');
        $this->user = $userRepository->findByProviderId('github', $ownerId);
        if (!$this->user) {
            $this->app->jsonResponse(
            [
                'status' => 'error',
                'message' => 'Owner Id not found',
            ],
            200
            );
        }

        $normalizedVersion = VersionParser::Normalize($tag);
        if (!$normalizedVersion) {
            $this->app->jsonResponse(
            [
                'status' => 'error',
                'message' => 'This tag does not look like a release tag',
            ],
            200
            );

            return;
        }

        if (!$this->findRegisteredExension($extensionName)) {
            return;
        }

        $log = new BufferIO();

        try {
            $driver = new \PickleWeb\Repository\Github($repository, false, $this->app->config('cache_dir'), $log);
            $extension = new Extension();
            $extension->setFromRepository($driver, $log);
        } catch (\Exception $e) {
            $this->app->jsonResponse([
                'status' => 'error',
                'message' => $extensionName.'-'.$tag.' error on import:'.$e->getMessage(),
            ],
            500);

            return;
        }

        $vendorName = $extension->getVendor();
        $repositoryName = $extension->getRepositoryName();

        $path = $this->app->config('json_path').'/'.$vendorName.'/'.$repositoryName.'.json';
        $json = $extension->serialize();
        if (!$json) {
            $this->app->jsonResponse([
                'status' => 'error',
                'message' => $extensionName.'-'.$tag.' error on import:'.$e->getMessage(),
            ],
            500);

            return;
        }
        $redis = $this->app->container->get('redis.client');
        $extensionRepository = new ExtensionRepository($redis);
        $extensionRepository->persist($extension, $this->user);

        $rest = new Rest($extension, $this->app);
        $rest->update();

        $this->app->jsonResponse([
            'status' => 'success',
            'message' => $extensionName.'-'.$tag.' imported',
            ],
            200);

        return;
    }
}
