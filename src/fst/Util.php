<?php

namespace ftIndex\fst;

use ftIndex\analyses\hunspell\IllegalArgumentException;

/**
 * Class Util
 *
 * @package ftIndex\fst
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/16/19 3:50 PM
 */
class Util
{

    public static function toUTF16($str, &$ints) {
        $str = iconv('UTF-8', 'UTF-16BE', $str);
        $strlen = mb_strlen($str, 'UTF-16BE');
        $ints = [];
        $pos = 0;
        for ($i = 0; $i < $strlen; $i++) {
            $chr = mb_substr($str, $i, 1, 'UTF-16BE');
            $int = hexdec(bin2hex($chr));
            if ($int > 0xFFFF) {
                $ints[$pos++] = $int >> 16;
                $ints[$pos++] = $int & 0xFFFF;
            } else {
                $ints[$pos++] = $int;
            }
        }
    }

    public static function toUTF32($str, &$ints) {
        $strlen = mb_strlen($str);
        var_dump($strlen);
        $ints = [];
        for ($i = 0; $i < $strlen; $i++) {
            $chr = mb_substr($str, $i, 1);
            $ints[$i] = \IntlChar::ord($chr);
        }
    }

    public static function array_cmp($arrayA, $arrayB)
    {
        if ($arrayA == $arrayB) {
            return 0;
        }

        $aInts = $arrayA;
        $aUpto = 0;
        $bInts = $arrayB;
        $bUpto = 0;

        $aStop = $aUpto + min(count($arrayA), count($arrayB));

        while ($aUpto < $aStop) {
            $aInt = $aInts[$aUpto++];
            $bInt = $bInts[$bUpto++];
            if ($aInt > $bInt) {
                return 1;
            } else {
                if ($aInt < $bInt) {
                    return -1;
                }
            }
        }

        // One is a prefix of the other, or, they are equal:
        return count($arrayA) - count($arrayB);

/*
        $array1Len = count($array1);
        $array2Len = count($array2);

        if ($array1Len !== $array2Len) {
            return $array1Len - $array2Len;
        }

        foreach ($array1 as $i => $v) {
            if ($v < $array2[$i]) {
                return -1;
            } elseif ($v > $array2[$i]) {
                return 1;
            }
        }

        return 0;*/
    }

    public static function arraycopy($src, $srcOffset, &$dst, $dstOffset, $length)
    {
        for ($i = 0; $i < $length; $i++) {
            $dst[$dstOffset + $i] = $src[$srcOffset + $i];
        }
    }

    public static function growByteArray(array $array, int $minSize)
    {
        if  ($minSize < 0) {
            throw new \AssertionError("size must be positive (got " . $minSize . "): likely integer overflow?");
        }

        if (count($array) < $minSize) {
            $newSize = self::oversize($minSize, 1);
            $newArray = array_fill(0, $newSize, 0);
            self::arraycopy($array, 0, $newArray, 0, count($array));
            return $newArray;
        } else {
            return $array;
        }
    }

    public static function growIntArray(array $array, int $minSize)
    {
        if  ($minSize < 0) {
            throw new \AssertionError("size must be positive (got " . $minSize . "): likely integer overflow?");
        }

        if (count($array) < $minSize) {
            $newSize = self::oversize($minSize, 4);
            $newArray = array_fill(0, $newSize, 0);
            self::arraycopy($array, 0, $newArray, 0, count($array));
            return $newArray;
        } else {
            return $array;
        }
    }

    public static function oversize(int $minTargetSize, int $bytesPerElement)
    {
        if ($minTargetSize < 0) {
            throw new IllegalArgumentException("invalid array size " . $minTargetSize);
        } else if ($minTargetSize == 0) {
            return 0;
        } else if ($minTargetSize > PHP_INT_MAX - 8) {
            throw new IllegalArgumentException("requested array size " . $minTargetSize . " exceeds maximum array in java (" . (PHP_INT_MAX - 8) . ")");
        } else {
            $extra = $minTargetSize >> 3;
            if ($extra < 3) {
                $extra = 3;
            }

            $newSize = $minTargetSize + $extra;
            if ($newSize + 7 >= 0 && $newSize + 7 <= PHP_INT_MAX - 8) {
                switch($bytesPerElement) {
                    case 1:
                        return $newSize + 7 & 2147483640;
                    case 2:
                        return $newSize + 3 & 2147483644;
                    case 3:
                    case 5:
                    case 6:
                    case 7:
                    case 8:
                    default:
                        return $newSize;
                    case 4:
                        return $newSize + 1 & 2147483646;
                }
            } else {
                return PHP_INT_MAX - 8;
            }
        }
    }

    public static function equals(array $a, array $a2): bool
    {
        if ($a == $a2) {
            return true;
        }
        if ($a == null || $a2 == null) {
            return false;
        }

        $length = count($a);
        if (count($a2) != $length) {
            return false;
        }

        for ($i = 0; $i < $length; $i++) {
            if ($a[$i] != $a2[$i]) {
                return false;
            }
        }

        return true;
    }
}