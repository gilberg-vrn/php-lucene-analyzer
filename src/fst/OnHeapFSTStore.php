<?php

namespace ftIndex\fst;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\store\DataInput;
use ftIndex\store\DataOutput;

/** Provides storage of finite state machine (FST),
 *  using byte array or byte store allocated on heap.
 *
 * @lucene.experimental
 */
class OnHeapFSTStore implements FSTStore {

    const BASE_RAM_BYTES_USED = 24;

    /**
     * A {@link BytesStore}, used during building, or during reading when
     *  the FST is very large (more than 1 GB).  If the FST is less than 1
     *  GB then bytesArray is set instead.
     * @var array|BytesStore
     */
    private $bytes;

    /** Used at read time when the FST fits into a single byte[]. */
    private $bytesArray;

    private $maxBlockBits;

    public function __construct($maxBlockBits) {
        if ($maxBlockBits < 1 || $maxBlockBits > 30) {
            throw new IllegalArgumentException("maxBlockBits should be 1 .. 30; got " . $maxBlockBits);
        }

        $this->maxBlockBits = $maxBlockBits;
    }

    public function init(DataInput $in, $numBytes) {
        if ($numBytes > 1 << $this->maxBlockBits) {
            // FST is big: we need multiple pages
            $this->bytes = []; //new BytesStore(in, numBytes, 1<<this.maxBlockBits);
        } else {
            // FST fits into a single block: use ByteArrayBytesStoreReader for less overhead
            $this->bytesArray = []; //new byte[(int) numBytes];
            $in->readBytes($this->bytesArray, 0, $numBytes);
        }
    }

    public function ramBytesUsed() {
        $size = self::BASE_RAM_BYTES_USED;
        if ($this->bytesArray != null) {
            $size += count($this->bytesArray);
        } else {
            $size += sizeof($this->bytes);
        }

        return $size;
    }

    public function getReverseBytesReader() {
        if ($this->bytesArray != null) {
            return new ReverseBytesReader($this->bytesArray);
        } else {
            return $this->bytes->getReverseReader();
        }
    }

    public function writeTo(DataOutput $out) {
        if ($this->bytes != null) {
            $numBytes = count($this->bytes);
            $out->writeVLong($numBytes);
            $out->writeBytes($this->bytes, 0, $numBytes);
        } else {
//            assert bytesArray != null;
            if ($this->bytesArray == null) {
                throw new \AssertionError('NOT: bytesArray != null');
            }
            $out->writeVLong(count($this->bytesArray));
            $out->writeBytes($this->bytesArray, 0, count($this->bytesArray));
        }
    }
}
