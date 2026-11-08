<?php

namespace Estasoft\HandoverPassport\Vendor\DeepCopy\Matcher\Doctrine;

use Estasoft\HandoverPassport\Vendor\DeepCopy\Matcher\Matcher;
use Doctrine\Persistence\Proxy;

/**
 * @final
 */
class DoctrineProxyMatcher implements Matcher
{
    /**
     * Matches a Doctrine Proxy class.
     *
     * {@inheritdoc}
     */
    public function matches(object $object, string $property)
    {
        return $object instanceof Proxy;
    }
}
