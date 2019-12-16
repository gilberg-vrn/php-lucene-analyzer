<?php

namespace ftIndex\analyses\morphology;

/**
 * Class BaseLetterDecoderEncoder
 *
 * @package ftIndex\analyses\morphology
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 2:32 PM
 */
abstract class BaseLetterDecoderEncoder implements LetterDecoderEncoder
{
    public function encodeToArray(string $s): array
    {
        $integers = [];
        while (mb_strlen($s) > 6) {
            $integers[] = $this->encode(mb_substr($s, 0, 6));
            $s = mb_substr($s, 6);
        }
        $integers[] = $this->encode($s);

        return $integers;
    }

    public function decodeArray(array $array): string
    {
        $result = "";
        /** @var int $i */
        foreach ($array as $i) {
            $result .= $this->decode($i);
        }

        return $result;
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
}