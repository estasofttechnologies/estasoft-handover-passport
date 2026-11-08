<?php

namespace Estasoft\HandoverPassport\Vendor\DeepCopy\Matcher;

interface Matcher
{
    /**
     * @param object $object
     * @param string $property
     *
     * @return boolean
     */
    public function matches(object $object, string $property);
}
