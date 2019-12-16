<?php

namespace ftIndex\util;

/**
 * Class BitUtil
 *
 * @package phpLuceneCore\util
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    8/7/19 2:02 PM
 */
class BitUtil
{

    // magic numbers for bit interleaving
    private static $MAGIC = [
        0x5555555555555555, 0x3333333333333333,
        0x0F0F0F0F0F0F0F0F, 0x00FF00FF00FF00FF,
        0x0000FFFF0000FFFF, 0x00000000FFFFFFFF,
        0xAAAAAAAAAAAAAAAA
    ];
// shift values for bit interleaving
    private static $SHIFT = [1, 2, 4, 8, 16];

    private function __construct()
    {
    } // no instance

    // The pop methods used to rely on bit-manipulation tricks for speed but it
    // turns out that it is faster to use the Long.bitCount method (which is an
    // intrinsic since Java 6u18) in a naive loop, see LUCENE-2221

    /** Returns the number of set bits in an array of longs.
     *
     * @param int[] $arr
     * @param int   $wordOffset
     * @param int   $numWords
     *
     * @return int
     */
    public static function pop_array($arr, $wordOffset, $numWords)
    {
        $popCount = 0;
        for ($i = $wordOffset, $end = $wordOffset + $numWords; $i < $end; ++$i) {
            $popCount += self::bitCount($arr[$i]);
        }
        return $popCount;
    }

    private static function bitCount($value)
    {
        $bitCount = count_chars(base_convert($value, 10, 2));
        return isset($bitCount['1']) ? $bitCount['1'] : 0;
    }

    /** Returns the popcount or cardinality of the two sets after an intersection.
     *  Neither array is modified.
     *
     * @param int[] $arr1
     * @param int[] $arr2
     * @param int   $wordOffset
     * @param int   $numWords
     *
     * @return int
     */
    public static function pop_intersect($arr1, $arr2, $wordOffset, $numWords)
    {
        $popCount = 0;
        for ($i = $wordOffset, $end = $wordOffset + $numWords; $i < $end; ++$i) {
            $popCount += self::bitCount($arr1[$i] & $arr2[$i]);
        }
        return $popCount;
    }

    /** Returns the popcount or cardinality of the union of two sets.
     *  Neither array is modified. */
    public static function pop_union($arr1, $arr2, $wordOffset, $numWords)
    {
        $popCount = 0;
        for ($i = $wordOffset, $end = $wordOffset + $numWords; $i < $end; ++$i) {
            $popCount += self::bitCount($arr1[$i] | $arr2[$i]);
        }
        return $popCount;
    }

    /** Returns the popcount or cardinality of {@code A & ~B}.
     *  Neither array is modified. */
    public static function pop_andnot($arr1, $arr2, $wordOffset, $numWords)
    {
        $popCount = 0;
        for ($i = $wordOffset, $end = $wordOffset + $numWords; $i < $end; ++$i) {
            $popCount += self::bitCount($arr1[$i] & ~$arr2[$i]);
        }
        return $popCount;
    }

    /** Returns the popcount or cardinality of A ^ B
     * Neither array is modified. */
    public static function pop_xor($arr1, $arr2, $wordOffset, $numWords)
    {
        $popCount = 0;
        for ($i = $wordOffset, $end = $wordOffset + $numWords; $i < $end; ++$i) {
            $popCount += self::bitCount($arr1[$i] ^ $arr2[$i]);
        }
        return $popCount;
    }

    /** returns the next highest power of two, or the current value if it's already a power of two or zero*/
    public static function nextHighestPowerOfTwo(int $v)
    {
        $v--;
        $v |= $v >> 1;
        $v |= $v >> 2;
        $v |= $v >> 4;
        $v |= $v >> 8;
        $v |= $v >> 16;
        $v |= $v >> 32;
        $v++;
        return $v;
    }

    /**
     * Interleaves the first 32 bits of each long value
     *
     * Adapted from: http://graphics.stanford.edu/~seander/bithacks.html#InterleaveBMN
     */
    public static function interleave(int $even, int $odd)
    {
        $v1 = 0x00000000FFFFFFFF & $even;
        $v2 = 0x00000000FFFFFFFF & $odd;
        $v1 = ($v1 | ($v1 << self::$SHIFT[4])) & self::$MAGIC[4];
        $v1 = ($v1 | ($v1 << self::$SHIFT[3])) & self::$MAGIC[3];
        $v1 = ($v1 | ($v1 << self::$SHIFT[2])) & self::$MAGIC[2];
        $v1 = ($v1 | ($v1 << self::$SHIFT[1])) & self::$MAGIC[1];
        $v1 = ($v1 | ($v1 << self::$SHIFT[0])) & self::$MAGIC[0];

        $v2 = ($v2 | ($v2 << self::$SHIFT[4])) & self::$MAGIC[4];
        $v2 = ($v2 | ($v2 << self::$SHIFT[3])) & self::$MAGIC[3];
        $v2 = ($v2 | ($v2 << self::$SHIFT[2])) & self::$MAGIC[2];
        $v2 = ($v2 | ($v2 << self::$SHIFT[1])) & self::$MAGIC[1];
        $v2 = ($v2 | ($v2 << self::$SHIFT[0])) & self::$MAGIC[0];

        return ($v2 << 1) | $v1;
    }

    /**
     * Deinterleaves long value back to two concatenated 32bit values
     */
    public static function deinterleave(int $b)
    {
        $b &= self::$MAGIC[0];
        $b = ($b ^ (self::uRShift($b, self::$SHIFT[0]))) & self::$MAGIC[1];
        $b = ($b ^ (self::uRShift($b, self::$SHIFT[1]))) & self::$MAGIC[2];
        $b = ($b ^ (self::uRShift($b, self::$SHIFT[2]))) & self::$MAGIC[3];
        $b = ($b ^ (self::uRShift($b, self::$SHIFT[3]))) & self::$MAGIC[4];
        $b = ($b ^ (self::uRShift($b, self::$SHIFT[4]))) & self::$MAGIC[5];
        return $b;
    }

    /**
     * flip flops odd with even bits
     */
    public static final function flipFlop($b)
    {
        $b = ($b & self::$MAGIC[6]);
        return (self::uRShift($b, 1)) | (($b & self::$MAGIC[0]) << 1);
    }

    /**
     * <a href="https://developers.google.com/protocol-buffers/docs/encoding#types">Zig-zag</a>
     * encode the provided long. Assuming the input is a signed long whose
     * absolute value can be stored on <tt>n</tt> bits, the returned value will
     * be an unsigned long that can be stored on <tt>n+1</tt> bits.
     */
    public static function zigZagEncode($l)
    {
        return ($l >> 63) ^ ($l << 1);
    }

    /** Decode a long previously encoded with {@link #zigZagEncode(long)}. */
    public static function zigZagDecode($l)
    {
        return (self::uRShift($l, 1) ^ -($l & 1));
    }

    public static function uRShift(&$a, $b)
    {
        if ($b == 0) {
            return $b;
        }

        $a = ($a >> $b) & ~(1 << (8 * PHP_INT_SIZE - 1) >> ($b - 1));

        return $a;
    }
}