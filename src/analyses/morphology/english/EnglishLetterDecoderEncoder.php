<?php

namespace ftIndex\analyses\morphology\english;

use ftIndex\analyses\morphology\LetterDecoderEncoder;
use ftIndex\analyses\morphology\SuffixToLongException;
use ftIndex\analyses\morphology\WrongCharaterException;

/**
 * Class EnglishLetterDecoderEncoder
 *
 * @package ftIndex\analyses\morphology\english
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 12:27 PM
 */
class EnglishLetterDecoderEncoder implements LetterDecoderEncoder
{
    const ENGLISH_SMALL_LETTER_OFFSET = 96;
    const SUFFIX_LENGTH               = 6;
    const DASH_CHAR                   = 45;
    const DASH_CODE                   = 27;

    public function encode(string $string): int
    {
        $stringLength = mb_strlen($string);
        if ($stringLength > self::SUFFIX_LENGTH) {
            throw new SuffixToLongException("Suffix length should not be greater then " . self::SUFFIX_LENGTH);
        }
        $result = 0;
        for ($i = 0; $i < $stringLength; $i++) {
            $c = \IntlChar::ord(mb_substr($string, $i, 1)) - self::ENGLISH_SMALL_LETTER_OFFSET;
            if ($c == 45 - self::ENGLISH_SMALL_LETTER_OFFSET) {
                $c = self::DASH_CODE;
            }
            if ($c < 0 || $c > 27) {
                throw new WrongCharaterException("Symbol " . mb_substr($string, $i, 1) . " is not small english letter");
            }
            $result = $result * 28 + $c;
        }
        for ($i = $stringLength; $i < self::SUFFIX_LENGTH; $i++) {
            $result *= 28;
        }
        return $result;
    }

    public function encodeToArray(string $s): array
    {
        $integers = [];
        while (mb_strlen($s) > self::SUFFIX_LENGTH) {
            $integers[] = $this->encode(mb_substr($s, 0, 6));
            $s = mb_substr($s, 6);
        }
        $integers[] = $this->encode($s);

        return $integers;
    }

    public function decodeArray(array $array): string
    {
        $result = "";
        foreach ($array as $i) {
            $result .= $this->decode($i);
        }
        return $result;
    }

    public function decode(int $suffixN): string
    {
        $result = "";

        while ($suffixN > 27) {
            $c = $suffixN % 28 + self::ENGLISH_SMALL_LETTER_OFFSET;
            if ($c == self::ENGLISH_SMALL_LETTER_OFFSET) {
                $suffixN /= 28;
                continue;
            }
            if ($c == self::DASH_CODE + self::ENGLISH_SMALL_LETTER_OFFSET) {
                $c = self::DASH_CHAR;
            }
            $result = \IntlChar::chr($c) . $result;
            $suffixN /= 28;
        }

        $c = $suffixN + self::ENGLISH_SMALL_LETTER_OFFSET;
        if ($c == self::DASH_CODE + self::ENGLISH_SMALL_LETTER_OFFSET) {
            $c = self::DASH_CHAR;
        }
        $result = \IntlChar::chr($c) . $result;

        return $result;
    }

    public function checkCharacter(string $c): bool
    {
        $code = \IntlChar::ord($c);
        if ($code == 45) {
            return true;
        }

        $code -= self::ENGLISH_SMALL_LETTER_OFFSET;
        if ($code > 0 && $code < 27) {
            return true;
        }

        return false;
    }


    public function checkString(string $word): bool
    {
        for ($i = 0; $i < mb_strlen($word); $i++) {
            if (!$this->checkCharacter(mb_substr($word, $i, 1))) {
                return false;
            }
        }

        return true;
    }

    public function cleanString(string $s): string
    {
        return $s;
    }

}
