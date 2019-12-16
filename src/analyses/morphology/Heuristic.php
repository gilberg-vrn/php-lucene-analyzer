<?php

namespace ftIndex\analyses\morphology;

/**
 * Class Heuristic
 *
 * @package ftIndex\analyses\morphology
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 2:21 PM
 */
class Heuristic
{
    /** @var int */
    protected $actualSuffixLength;
    /** @var String */
    protected $actualNormalSuffix;
    /** @var int */
    protected $formMorphInfo;
    /** @var int */
    protected $normalFormMorphInfo;

    public function __construct(string $s)
    {
        $strings = preg_split('/\|/u', $s);
        $this->actualSuffixLength = (int)$strings[0];
        $this->actualNormalSuffix = $strings[1];
        $this->formMorphInfo = (int)($strings[2]);
        $this->normalFormMorphInfo = (int)($strings[3]);
    }

    public static function Heuristic(int $actualSuffixLength, string $actualNormalSuffix, int $formMorphInfo, int $normalFormMorphInfo)
    {
        return new self("{$actualSuffixLength}|{$actualNormalSuffix}|{$formMorphInfo}|{$normalFormMorphInfo}");
    }

    public function transformWord(string $w): string
    {
        if (mb_strlen($w) - $this->actualSuffixLength < 0) {
            return $w;
        }
        return mb_substr($w, 0, mb_strlen($w) - $this->actualSuffixLength) . $this->actualNormalSuffix;
    }

    public function getActualSuffixLength(): int
    {
        return $this->actualSuffixLength;
    }

    public function getActualNormalSuffix(): string
    {
        return $this->actualNormalSuffix;
    }

    public function getFormMorphInfo(): int
    {
        return $this->formMorphInfo;
    }

    public function getNormalFormMorphInfo(): int
    {
        return $this->normalFormMorphInfo;
    }

    public function __toString(): string
    {
        return "{$this->actualSuffixLength}|{$this->actualNormalSuffix}|{$this->formMorphInfo}|{$this->normalFormMorphInfo}";
    }
}