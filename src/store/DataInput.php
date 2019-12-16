<?php

namespace ftIndex\store;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\analyses\hunspell\IOException;
use ftIndex\util\BitUtil;

abstract class DataInput
{

    const SKIP_BUFFER_SIZE = 1024;

    /* This buffer is used to skip over bytes with the default implementation of
     * skipBytes. The reason why we need to use an instance member instead of
     * sharing a single instance across threads is that some delegating
     * implementations of DataInput might want to reuse the provided buffer in
     * order to eg. update the checksum. If we shared the same buffer across
     * threads, then another thread might update the buffer while the checksum is
     * being computed, making it invalid. See LUCENE-5583 for more information.
     */
    /** @var byte[] */
    private $skipBuffer;

    /** Reads and returns a single byte.
     * @see DataOutput#writeByte(byte)
     */
    abstract public function readByte();

    /** Reads a specified number of bytes into an array at the
     * specified offset with control over whether the read
     * should be buffered (callers who have their own buffer
     * should pass in "false" for useBuffer).  Currently only
     * {@link BufferedIndexInput} respects this parameter.
     *
     * @param array $b the array to read bytes into
     * @param int $offset the offset in the array to start storing bytes
     * @param int $len the number of bytes to read
     * @param bool $useBuffer set to false if the caller will handle
     * buffering.
     *
     * @see DataOutput#writeBytes(byte[],int)
     */
    abstract public function readBytes(&$b, $offset, $len, $useBuffer = false);

    /** Reads two bytes and returns a short.
     * @see DataOutput#writeByte(byte)
     */
    public function readShort()
    {
        return ((($this->readByte() & 0xFF) << 8) | ($this->readByte() & 0xFF));
    }

    /** Reads four bytes and returns an int.
     * @see DataOutput#writeInt(int)
     */
    public function readInt()
    {
        return (($this->readByte() & 0xFF) << 24) | (($this->readByte() & 0xFF) << 16)
            | (($this->readByte() & 0xFF) << 8) | ($this->readByte() & 0xFF);
    }

    /** Reads an int stored in variable-length format.  Reads between one and
     * five bytes.  Smaller values take fewer bytes.  Negative numbers are
     * supported, but should be avoided.
     * <p>
     * The format is described further in {@link DataOutput#writeVInt(int)}.
     *
     * @see DataOutput#writeVInt(int)
     */
    public function readVInt()
    {
        /* This is the original code of this method,
         * but a Hotspot bug (see LUCENE-2975) corrupts the for-loop if
         * readByte() is inlined. So the loop was unwinded!
        byte b = readByte();
        int i = b & 0x7F;
        for (int shift = 7; (b & 0x80) != 0; shift += 7) {
          b = readByte();
          i |= (b & 0x7F) << shift;
        }
        return i;
        */
        $b = $this->readByte();
        if ($b >= 0) {
            return $b;
        }
        $i = $b & 0x7F;
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 7;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 14;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 21;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        // Warning: the next ands use 0x0F / 0xF0 - beware copy/paste errors:
        $i |= ($b & 0x0F) << 28;
        if (($b & 0xF0) == 0) {
            return $i;
        }
        throw new IOException("Invalid vInt detected (too many bits)");
    }

    /**
     * Read a {@link BitUtil#zigZagDecode(int) zig-zag}-encoded
     * {@link #readVInt() variable-length} integer.
     * @see DataOutput#writeZInt(int)
     */
    public function readZInt()
    {
        return BitUtil::zigZagDecode($this->readVInt());
    }

    /** Reads eight bytes and returns a long.
     * @see DataOutput#writeLong(long)
     */
    public function readLong()
    {
        return (($this->readInt()) << 32) | ($this->readInt() & 0xFFFFFFFF);
    }

    /** Reads a long stored in variable-length format.  Reads between one and
     * nine bytes.  Smaller values take fewer bytes.  Negative numbers are not
     * supported.
     * <p>
     * The format is described further in {@link DataOutput#writeVInt(int)}.
     *
     * @see DataOutput#writeVLong(long)
     */
    public function readVLong($allowNegative = false)
    {
        /* This is the original code of this method,
         * but a Hotspot bug (see LUCENE-2975) corrupts the for-loop if
         * readByte() is inlined. So the loop was unwinded!
        byte b = readByte();
        long i = b & 0x7F;
        for (int shift = 7; (b & 0x80) != 0; shift += 7) {
          b = readByte();
          i |= (b & 0x7FL) << shift;
        }
        return i;
        */
        $b = $this->readByte();
        if ($b >= 0) {
            return $b;
        }
        $i = $b & 0x7F;
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 7;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 14;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 21;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 28;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 35;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 42;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 49;
        if ($b >= 0) {
            return $i;
        }
        $b = $this->readByte();
        $i |= ($b & 0x7F) << 56;
        if ($b >= 0) {
            return $i;
        }
        if ($allowNegative) {
            $b = $this->readByte();
            $i |= ($b & 0x7F) << 63;
            if ($b == 0 || $b == 1) {
                return $i;
            }

            throw new IOException("Invalid vLong detected (more than 64 bits)");
        } else {
            throw new IOException("Invalid vLong detected (negative values disallowed)");
        }
    }

    /**
     * Read a {@link BitUtil#zigZagDecode(long) zig-zag}-encoded
     * {@link #readVLong() variable-length} integer. Reads between one and ten
     * bytes.
     * @see DataOutput#writeZLong(long)
     */
    public function readZLong()
    {
        return BitUtil::zigZagDecode($this->readVLong(true));
    }

    /** Reads a string.
     * @see DataOutput#writeString(String)
     */
    public function readString()
    {
        $length = $this->readVInt();
        $bytes = []; //new byte[$length];
        $this->readBytes($bytes, 0, $length);
        return implode('', $bytes);
    }

    /** Returns a clone of this stream.
     *
     * <p>Clones of a stream access the same data, and are positioned at the same
     * point as the stream they were cloned from.
     *
     * <p>Expert: Subclasses must ensure that clones may be positioned at
     * different points in the input from each other and from the stream they
     * were cloned from.
     */
    public function clone()
    {
        return clone $this;
    }

    /**
     * Reads a Map&lt;String,String&gt; previously written
     * with {@link DataOutput#writeMapOfStrings(Map)}.
     * @return array An immutable map containing the written contents.
     */
    public function readMapOfStrings()
    {
        $count = $this->readVInt();
        if ($count == 0) {
            return [];
        } elseif ($count == 1) {
            return [$this->readString() => $this->readString()];
        } else {
            $map = [];
            for ($i = 0; $i < $count; $i++) {
                $key = $this->readString();
                $val = $this->readString();
                $map[$key] = $val;
            }
            return $map;
        }
    }

    /**
     * Reads a Set&lt;String&gt; previously written
     * with {@link DataOutput#writeSetOfStrings(Set)}.
     * @return array An immutable set containing the written contents.
     */
    public function readSetOfStrings()
    {
        $count = $this->readVInt();
        if ($count == 0) {
            return [];
        } elseif ($count == 1) {
            return [$this->readString()];
        } else {
            $set = [];
            for ($i = 0; $i < $count; $i++) {
                $set[] = $this->readString();
            }
            return $set;
        }
    }

    /**
     * Skip over <code>numBytes</code> bytes. The contract on this method is that it
     * should have the same behavior as reading the same number of bytes into a
     * buffer and discarding its content. Negative values of <code>numBytes</code>
     * are not supported.
     */
    public function skipBytes($numBytes)
    {
        if ($numBytes < 0) {
            throw new IllegalArgumentException("numBytes must be >= 0, got {$numBytes}");
        }
        if ($this->skipBuffer == null) {
            $this->skipBuffer = [];
        }
        for ($skipped = 0; $skipped < $numBytes;) {
            $step = min(self::SKIP_BUFFER_SIZE, $numBytes - $skipped);
            $this->readBytes($this->skipBuffer, 0, $step, false);
            $skipped += $step;
        }
    }

}
