<?php

namespace ftIndex\analyses\morphology;

/**
 * File
 *
 * @author emelyanov
 * @date   9/11/19
 */
interface LetterDecoderEncoder
{
    public function encode(string $string): int;

    public function encodeToArray(string $s): array;

    public function decodeArray(array $array): string;

    public function decode(int $suffixN): string;

    public function checkCharacter(string $c): bool;

    public function checkString(string $word): bool;

    public function cleanString(string $s): string;
}