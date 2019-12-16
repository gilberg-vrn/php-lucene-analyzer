<?php

namespace ftIndex\analyses\morphology\russian;

use ftIndex\analyses\morphology\LetterDecoderEncoder;
use ftIndex\analyses\morphology\SuffixToLongException;
use ftIndex\analyses\morphology\WrongCharaterException;

/**
 * Class RussianLetterDecoderEncoder
 * This helper class allow encode suffix of russian word
 * to long value and decode from it.
 * Assumed that suffix contains only small russian letters and dash.
 * Also assumed that letter � and � coinsed.
 *
 * @package ftIndex\analyses\morphology\russian
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 3:27 PM
 */
class RussianLetterDecoderEncoder implements LetterDecoderEncoder
{
    const RUSSIAN_SMALL_LETTER_OFFSET = 1071;
    const WORD_PART_LENGHT            = 6;
    const EE_CHAR                     = 34;
    const E_CHAR                      = 6;
    const DASH_CHAR                   = 45;
    const DASH_CODE                   = 33;

    public function encode(string $string): int
    {
//        $string = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strlen($string) > self::WORD_PART_LENGHT) {
            throw new SuffixToLongException("Suffix length should not be greater then " . self::WORD_PART_LENGHT . " {$string}");
        }
        $result = 0;
        $length = mb_strlen($string);
        for ($i = 0; $i < $length; $i++) {
            $c = \IntlChar::ord(mb_substr($string, $i, 1)) - self::RUSSIAN_SMALL_LETTER_OFFSET;
            if ($c == 45 - self::RUSSIAN_SMALL_LETTER_OFFSET) {
                $c = self::DASH_CODE;
            }
            if ($c == self::EE_CHAR) {
                $c = self::E_CHAR;
            }
            if ($c < 0 || $c > 33) {
                throw new WrongCharaterException('Symbol ' . mb_substr($string, $i, 1) . ' is not small cirillic letter');
            }
            $result = $result * 34 + $c;
        }
        for ($i = $length; $i < self::WORD_PART_LENGHT; $i++) {
            $result *= 34;
        }
        return $result;
    }

    public function encodeToArray(string $s): array
    {
        $integers = [];
        while (mb_strlen($s) > self::WORD_PART_LENGHT) {
            $integers[] = $this->encode(mb_substr($s, 0, self::WORD_PART_LENGHT));
            $s = mb_substr($s, self::WORD_PART_LENGHT);
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
        while ($suffixN > 33) {
            $c = $suffixN % 34 + self::RUSSIAN_SMALL_LETTER_OFFSET;
            if ($c == self::RUSSIAN_SMALL_LETTER_OFFSET) {
                $suffixN /= 34;
                continue;
            }
            if ($c == self::DASH_CODE + self::RUSSIAN_SMALL_LETTER_OFFSET) {
                $c = self::DASH_CHAR;
            }
            $result = \IntlChar::chr($c) . $result;
            $suffixN /= 34;
        }
        $c = $suffixN + self::RUSSIAN_SMALL_LETTER_OFFSET;
        if ($c == self::DASH_CODE + self::RUSSIAN_SMALL_LETTER_OFFSET) {
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
        $code -= self::RUSSIAN_SMALL_LETTER_OFFSET;
        if ($code > 0 && $code < 33) {
            return true;
        }

        return false;
    }

    public function checkString(string $word): bool
    {
//        $word = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        $length = mb_strlen($word);
        for ($i = 0; $i < $length; $i++) {
            if (!$this->checkCharacter(mb_substr($word, $i, 1))) {
                return false;
            }
        }

        return true;
    }

    public function cleanString(string $s): string
    {
        return preg_replace('/' . \IntlChar::chr(self::EE_CHAR + self::RUSSIAN_SMALL_LETTER_OFFSET) . '/u', \IntlChar::chr(self::E_CHAR + self::RUSSIAN_SMALL_LETTER_OFFSET), $s);
    }
}
