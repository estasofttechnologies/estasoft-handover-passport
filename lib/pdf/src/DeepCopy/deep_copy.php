<?php

namespace Estasoft\HandoverPassport\Vendor\DeepCopy;

use function function_exists;

if (false === function_exists('Estasoft\HandoverPassport\Vendor\DeepCopy\deep_copy')) {
    /**
     * Deep copies the given value.
     *
     * @param mixed $value
     * @param bool  $useCloneMethod
     *
     * @return mixed
     */
    function deep_copy(mixed $value, bool $useCloneMethod = false): mixed
    {
        return (new DeepCopy($useCloneMethod))->copy($value);
    }
}
