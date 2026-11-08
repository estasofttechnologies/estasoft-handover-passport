<?php

namespace Estasoft\HandoverPassport\Vendor\DeepCopy\TypeFilter;

/**
 * @final
 */
class ShallowCopyFilter implements TypeFilter
{
    /**
     * {@inheritdoc}
     */
    public function apply(mixed $element)
    {
        return clone $element;
    }
}
