<?php
/*
 * This file is part of the Bukashk0zzzLiipImagineSerializationBundle
 *
 * (c) Denis Golubovskiy <bukashk0zzz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bukashk0zzz\LiipImagineSerializationBundle\EventListener;

use Bukashk0zzz\LiipImagineSerializationBundle\Annotation\LiipImagineSerializableField;
use Bukashk0zzz\LiipImagineSerializationBundle\Event\UrlNormalizerEvent;
use Bukashk0zzz\LiipImagineSerializationBundle\Normalizer\UrlNormalizerInterface;
use Doctrine\Common\Annotations\CachedReader;
use JMS\Serializer\EventDispatcher\ObjectEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Doctrine\Common\Persistence\Proxy;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * JmsSerializeListenerAbstract
 *
 * @author Denis Golubovskiy <bukashk0zzz@gmail.com>
 */
class JmsSerializeListenerAbstract
{
    /**
     * @var RequestContext $requestContext Request context
     */
    protected $requestContext;

    /**
     * @var CachedReader $annotationReader Cached annotation reader
     */
    protected $annotationReader;

    /**
     * @var CacheManager $cacheManager LiipImagineBundle Cache Manager
     */
    protected $cacheManager;

    /**
     * @var StorageInterface $storage Vich storage
     */
    protected $vichStorage;

    /**
     * @var EventDispatcherInterface $eventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var array $config Bundle config
     */
    protected $config;

    /**
     * JmsSerializeListenerAbstract constructor.
     *
     * @param RequestContext           $requestContext
     * @param CachedReader             $annotationReader
     * @param CacheManager             $cacheManager
     * @param StorageInterface         $vichStorage
     * @param EventDispatcherInterface $eventDispatcher
     * @param array                    $config
     */
    public function __construct(
        RequestContext $requestContext,
        CachedReader $annotationReader,
        CacheManager $cacheManager,
        StorageInterface $vichStorage,
        EventDispatcherInterface $eventDispatcher,
        array $config
    ) {
        $this->requestContext = $requestContext;
        $this->annotationReader = $annotationReader;
        $this->cacheManager = $cacheManager;
        $this->vichStorage = $vichStorage;
        $this->eventDispatcher = $eventDispatcher;
        $this->config = $config;
    }

    /**
     * @param ObjectEvent $event Event
     * @return mixed
     */
    protected function getObject(ObjectEvent $event)
    {
        $object = $event->getObject();

        if ($object instanceof Proxy
            && ! $object->__isInitialized()
        ) {
            $object->__load();
        }

        return $object;
    }

    /**
     * @param LiipImagineSerializableField $liipAnnotation
     * @param object $object Serialized object
     * @param string $value Value of field
     *
     * @return array|string
     *
     * @throws \InvalidArgumentException
     */
    protected function serializeValue(LiipImagineSerializableField $liipAnnotation, $object, $value)
    {
        if ($vichField = $liipAnnotation->getVichUploaderField()) {
            $value = $this->vichStorage->resolveUri($object, $vichField);
        }

        $result = [];
        $value = $this->normalizeUrl($value, UrlNormalizerInterface::TYPE_ORIGIN);
        if (array_key_exists('includeOriginal', $this->config) && $this->config['includeOriginal']) {
            $result['original'] = (array_key_exists('includeHostForOriginal', $this->config) && $this->config['includeHostForOriginal'] && $liipAnnotation->getVichUploaderField())
                ? $this->getHostUrl().$value
                : $value;
        }

        $filters = $liipAnnotation->getFilter();
        if (is_array($filters)) {
            /** @var array $filters */
            foreach ($filters as $filter) {
                $result[$filter] = $this->prepareFilteredUrl($this->cacheManager->getBrowserPath($value, $filter));
            }

            return $result;
        }

        $filtered = $this->cacheManager->getBrowserPath($value, $filters);
        if (count($result) !== 0) {
            $result[$filters] = $this->prepareFilteredUrl($filtered);

            return $result;
        }

        return $filtered;
    }

    /**
     * Get host url (scheme, host, port)
     *
     * @return string Host url
     */
    protected function getHostUrl()
    {
        $url = $this->requestContext->getScheme().'://'.$this->requestContext->getHost();

        if ($this->requestContext->getScheme() === 'http' && $this->requestContext->getHttpPort() && $this->requestContext->getHttpPort() !== 80) {
            $url .= ':'.$this->requestContext->getHttpPort();
        } elseif ($this->requestContext->getScheme() === 'https' && $this->requestContext->getHttpsPort() && $this->requestContext->getHttpsPort() !== 443) {
            $url .= ':'.$this->requestContext->getHttpsPort();
        }

        return $url;
    }

    /**
     * Normalize url if needed
     *
     * @param string $url
     * @param string $normalizer
     * @return string
     */
    protected function normalizeUrl($url, $normalizer)
    {
        $url = $this->addPreNormalizeUrlEvent($normalizer, $url);

        if (array_key_exists($normalizer, $this->config) && $this->config[$normalizer]) {
            $normalizerClassName = $this->config[$normalizer];
            $normalizer = new $normalizerClassName();
            if ($normalizer instanceof UrlNormalizerInterface) {
                $url = $normalizer->normalize($url);
            }
        }

        return $url;
    }

    /**
     * If config demands, it will remove host and scheme (protocol) from passed url
     *
     * @param $url
     * @return string
     * @throws \InvalidArgumentException
     */
    private function prepareFilteredUrl($url)
    {
        if (array_key_exists('includeHost', $this->config) && !$this->config['includeHost']) {
            $url = $this->stripHostFromUrl($url);
        }

        return $this->normalizeUrl($url, UrlNormalizerInterface::TYPE_FILTERED);
    }

    /**
     * Removes host and scheme (protocol) from passed url
     *
     * @param $url
     * @return string
     * @throws \InvalidArgumentException
     */
    private function stripHostFromUrl($url)
    {
        $parts = parse_url($url);
        if ($parts !== false && array_key_exists('path', $parts)) {
            return array_key_exists('query', $parts) ? $parts['path'].'?'.$parts['query'] : $parts['path'];
        }

        throw new \InvalidArgumentException('Can\'t strip host from url, because can\'t parse url.');
    }

    /**
     * @param string $type
     * @param string $url
     *
     * @return string
     */
    private function addPreNormalizeUrlEvent($type, $url)
    {
        /** @var UrlNormalizerEvent $event */
        $event = $this->eventDispatcher->dispatch(
            $type === UrlNormalizerInterface::TYPE_ORIGIN
                ? UrlNormalizerEvent::ORIGIN
                : UrlNormalizerEvent::FILTERED,
            new UrlNormalizerEvent($url)
        );

        return $event->getUrl();
    }
}
