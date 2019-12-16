<?php

namespace ftIndex\store;

use ftIndex\analyses\hunspell\IllegalArgumentException;
use ftIndex\analyses\hunspell\IOException;
use ftIndex\util\BitUtil;

abstract class DataOutput
{

    /** Writes a single byte.
     * <p>
     * The most primitive data type is an eight-bit byte. Files are
     * accessed as sequences of bytes. All other data types are defined
     * as sequences of bytes, so file formats are byte-order independent.
     *
     * @see IndexInput#readByte()
     */
    public abstract function writeByte($b);

    /** Writes an array of bytes.
     *
     * @param string $b the bytes to write
     * @param int $offset the offset in the byte array
     * @param int $length the number of bytes to write
     *
     * @see DataInput#readBytes(byte[],int,int)
     */
    public abstract function writeBytes($b, $offset, $length = null);

    /** Writes an int as four bytes.
     * <p>
     * 32-bit unsigned integer written as four bytes, high-order bytes first.
     *
     * @see DataInput#readInt()
     */
    public function writeInt($i)
    {
        $this->writeByte(0xff & ($i >> 24));
        $this->writeByte(0xff & ($i >> 16));
        $this->writeByte(0xff & ($i >> 8));
        $this->writeByte(0xff & $i);
    }

    /** Writes a short as two bytes.
     * @see DataInput#readShort()
     */
    public function writeShort($i)
    {
        $this->writeByte(0xff & ($i >> 8));
        $this->writeByte(0xff & $i);
    }

    /** Writes an int in a variable-length format.  Writes between one and
     * five bytes.  Smaller values take fewer bytes.  Negative numbers are
     * supported, but should be avoided.
     * <p>VByte is a variable-length format for positive integers is defined where the
     * high-order bit of each byte indicates whether more bytes remain to be read. The
     * low-order seven bits are appended as increasingly more significant bits in the
     * resulting integer value. Thus values from zero to 127 may be stored in a single
     * byte, values from 128 to 16,383 may be stored in two bytes, and so on.</p>
     * <p>VByte Encoding Example</p>
     * <table cellspacing="0" cellpadding="2" border="0" summary="variable length encoding examples">
     * <tr valign="top">
     *   <th align="left">Value</th>
     *   <th align="left">Byte 1</th>
     *   <th align="left">Byte 2</th>
     *   <th align="left">Byte 3</th>
     * </tr>
     * <tr valign="bottom">
     *   <td>0</td>
     *   <td><code>00000000</code></td>
     *   <td></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>1</td>
     *   <td><code>00000001</code></td>
     *   <td></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>2</td>
     *   <td><code>00000010</code></td>
     *   <td></td>
     *   <td></td>
     * </tr>
     * <tr>
     *   <td valign="top">...</td>
     *   <td valign="bottom"></td>
     *   <td valign="bottom"></td>
     *   <td valign="bottom"></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>127</td>
     *   <td><code>01111111</code></td>
     *   <td></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>128</td>
     *   <td><code>10000000</code></td>
     *   <td><code>00000001</code></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>129</td>
     *   <td><code>10000001</code></td>
     *   <td><code>00000001</code></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>130</td>
     *   <td><code>10000010</code></td>
     *   <td><code>00000001</code></td>
     *   <td></td>
     * </tr>
     * <tr>
     *   <td valign="top">...</td>
     *   <td></td>
     *   <td></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>16,383</td>
     *   <td><code>11111111</code></td>
     *   <td><code>01111111</code></td>
     *   <td></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>16,384</td>
     *   <td><code>10000000</code></td>
     *   <td><code>10000000</code></td>
     *   <td><code>00000001</code></td>
     * </tr>
     * <tr valign="bottom">
     *   <td>16,385</td>
     *   <td><code>10000001</code></td>
     *   <td><code>10000000</code></td>
     *   <td><code>00000001</code></td>
     * </tr>
     * <tr>
     *   <td valign="top">...</td>
     *   <td valign="bottom"></td>
     *   <td valign="bottom"></td>
     *   <td valign="bottom"></td>
     * </tr>
     * </table>
     * <p>This provides compression while still being efficient to decode.</p>
     *
     * @param int $i Smaller values take fewer bytes.  Negative numbers are
     * supported, but should be avoided.
     *
     * @throws IOException If there is an I/O error writing to the underlying medium.
     * @see DataInput#readVInt()
     */
    public final function writeVInt($i)
    {
        while (($i & ~0x7F) != 0) {
            $this->writeByte(0xff & (($i & 0x7F) | 0x80));
            $i >>= 7;
        }
        $this->writeByte(0xff & $i);
    }

    /**
     * Write a {@link BitUtil#zigZagEncode(int) zig-zag}-encoded
     * {@link #writeVInt(int) variable-length} integer. This is typically useful
     * to write small signed ints and is equivalent to calling
     * <code>writeVInt(BitUtil.zigZagEncode(i))</code>.
     * @see DataInput#readZInt()
     * @throws IOException
     */
    public final function writeZInt($i)
    {
        $this->writeVInt(BitUtil::zigZagEncode($i));
    }

    /** Writes a long as eight bytes.
     * <p>
     * 64-bit unsigned integer written as eight bytes, high-order bytes first.
     *
     * @see DataInput#readLong()
     */
    public function writeLong($i)
    {
        $this->writeInt($i >> 32);
        $this->writeInt($i);
    }

    /** Writes an long in a variable-length format.  Writes between one and nine
     * bytes.  Smaller values take fewer bytes.  Negative numbers are not
     * supported.
     * <p>
     * The format is described further in {@link DataOutput#writeVInt(int)}.
     * @see DataInput#readVLong()
     * @throws IllegalArgumentException
     */
    public final function writeVLong($i)
    {
        if ($i < 0) {
            throw new IllegalArgumentException("cannot write negative vLong (got: {$i})");
        }
        $this->writeSignedVLong($i);
    }

    // write a potentially negative vLong
    private function writeSignedVLong($i)
    {
        while (($i & ~0x7F) != 0) {
            $this->writeByte(0xff & (($i & 0x7F) | 0x80));
            $i >>= 7;
        }
        $this->writeByte(0xff & $i);
    }

    /**
     * Write a {@link BitUtil#zigZagEncode(long) zig-zag}-encoded
     * {@link #writeVLong(long) variable-length} long. Writes between one and ten
     * bytes. This is typically useful to write small signed ints.
     * @see DataInput#readZLong()
     */
    public final function writeZLong($i)
    {
        $this->writeSignedVLong(BitUtil::zigZagEncode($i));
    }

    /** Writes a string.
     * <p>
     * Writes strings as UTF-8 encoded bytes. First the length, in bytes, is
     * written as a {@link #writeVInt VInt}, followed by the bytes.
     *
     * @see DataInput#readString()
     * @throws IOException
     */
    public function writeString($s)
    {
        $utf8Result = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $this->writeVInt(count($utf8Result));
        $this->writeBytes($utf8Result, 0, count($utf8Result));
    }

    const COPY_BUFFER_SIZE = 16384;
    private $copyBuffer;

    /** Copy numBytes bytes from input to ourself. */
    public function copyBytes(DataInput $input, $numBytes)
    {
//    assert numBytes >= 0: "numBytes=" + numBytes;
        if ($numBytes < 0) {
            throw new \AssertionError("numBytes={numBytes}");
        }

        $left = $numBytes;
        if ($this->copyBuffer == null) {
            $this->copyBuffer = [];
        } //new byte[COPY_BUFFER_SIZE];
        while ($left > 0) {
            if ($left > self::COPY_BUFFER_SIZE) {
                $toCopy = self::COPY_BUFFER_SIZE;
            } else {
                $toCopy = (int)$left;
            }
            $input->readBytes($copyBuffer, 0, $toCopy);
            $this->writeBytes($copyBuffer, 0, $toCopy);
            $left -= $toCopy;
        }
    }

    /**
     * Writes a String map.
     * <p>
     * First the size is written as an {@link #writeVInt(int) vInt},
     * followed by each key-value pair written as two consecutive
     * {@link #writeString(String) String}s.
     *
     * @param array map Input map.
     *
     * @throws IOException
     */
    public function writeMapOfStrings($map)
    {
        $this->writeVInt(count($map));
        foreach ($map as $entryKey => $entryValue) {
            $this->writeString($entryKey);
            $this->writeString($entryValue);
        }
    }

    /**
     * Writes a String set.
     * <p>
     * First the size is written as an {@link #writeVInt(int) vInt},
     * followed by each value written as a
     * {@link #writeString(String) String}.
     *
     * @param array $set Input set.
     *
     * @throws IOException
     */
    public function writeSetOfStrings($set)
    {
        $this->writeVInt(count($set));
        foreach ($set as $value) {
            $this->writeString($value);
        }
    }
}
