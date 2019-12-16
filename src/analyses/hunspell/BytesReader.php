<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\store\DataInput;

/** Reads bytes stored in an FST. */
abstract class BytesReader extends DataInput
{
    /** Get current read position.
     * @return int
     */
    public abstract function getPosition();

    /** Set current read position.
     * @return void
     */
    public abstract function setPosition($pos);

    /** Returns true if this reader uses reversed bytes
     *  under-the-hood.
     * @return boolean
     */
    public abstract function reversed();
}