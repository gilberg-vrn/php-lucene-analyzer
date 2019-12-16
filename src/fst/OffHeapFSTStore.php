<?php

namespace ftIndex\fst;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\analyses\hunspell\IOException;
use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;
use ftIndex\store\FSTStore;
use ftIndex\store\IndexInput;
use ftIndex\analyses\hunspell\UnsupportedOperationException;

/** Provides off heap storage of finite state machine (FST),
 *  using underlying index input instead of byte store on heap
 *
 * @lucene.experimental
 */
class OffHeapFSTStore implements FSTStore {

    const BASE_RAM_BYTES_USED = 24;

    /** @var IndexInput */
    private $in;
    /** @var int */
    private $offset;
    /** @var int */
    private $numBytes;

    public function init(DataInput $in, $numBytes)
    {
        if ($in instanceof IndexInput) {
            $this->in = $in;
            $this->numBytes = $numBytes;
            $this->offset = $this->in->getFilePointer();
        } else {
            throw new IllegalArgumentException("parameter:in should be an instance of IndexInput for using OffHeapFSTStore, not a " . get_class($in));
        }
    }

    public function ramBytesUsed() {
        return self::BASE_RAM_BYTES_USED;
    }

    public function getReverseBytesReader() {
        try {
            return new ReverseRandomAccessReader($this->in->randomAccessSlice($this->offset, $this->numBytes));
        } catch (IOException $e) {
            throw new \RuntimeException('', 0, $e);
        }
    }

    public function writeTo(DataOutput $out)
    {
        throw new UnsupportedOperationException("writeToOutput operation is not supported for OffHeapFSTStore");
    }
}
