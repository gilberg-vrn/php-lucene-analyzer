<?php

namespace ftIndex\util\automaton;

/**
 * Class BitSet
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/25/19 6:10 PM
 */
class BitSet
{
    const ADDRESS_BITS_PER_WORD = 6;
    const BITS_PER_WORD         = 64; //1 << ADDRESS_BITS_PER_WORD;
    const BIT_INDEX_MASK        = 63; //BITS_PER_WORD - 1;

    public $words = [];
    public $size;


    /**
     * Given a bit index, return word index containing it.
     *
     * @param int $bitIndex
     *
     * @return int
     */
    private static function wordIndex(int $bitIndex): int
    {
        return $bitIndex >> self::ADDRESS_BITS_PER_WORD;
    }

    public function __construct($size = self::BITS_PER_WORD)
    {
        $this->size = $size;
        $this->initWords($size);
    }

    private function initWords(int $nBits)
    {
        for ($i = 0; $i < self::wordIndex($nBits - 1) + 1; $i++) {
            $this->words[$i] = 0;
        }
    }

    public function set($bit, $value = null)
    {
        if ($value === null || $value) {
            $this->words[self::wordIndex($bit)] |= 1 << $bit;
        } elseif ($value) {
            $this->words[self::wordIndex($bit)] |= 1 << $bit;
        } else {
            $this->clear($bit);
        }
    }

    public function get($bit)
    {
        return ($this->words[self::wordIndex($bit)] & (1 << $bit)) == (1 << $bit);
    }

    public function clear($bit = null)
    {
        if ($bit === null) {
            $this->initWords($this->size);

            return;
        }

        if ($this->get($bit)) {
            $this->words[self::wordIndex($bit)] ^= 1 << $bit;
        }
    }

    public function flip($bit)
    {
        $this->words[self::wordIndex($bit)] ^= 1 << $bit;
    }

    public function nextSetBit($index)
    {
        for ($i = $index; $i < $this->size; $i++) {
            if ($this->get($i)) {
                return $i;
            }
        }

        return -1;
    }

    public function cardinality(): int
    {
        $count = 0;
        foreach ($this->words as $word) {
            $count += substr_count(decbin($word), '1');
        }

        return $count;
    }

    public function andNot(BitSet $set)
    {
        // Perform logical (a & !b) on words in common
        for ($i = min(count($this->words), count($set->words)) - 1; $i >= 0; $i--) {
            $this->words[$i] &= ~$set->words[$i];
        }
    }

    public function isEmpty()
    {
        return $this->cardinality() === 0;
    }
    public function and(BitSet $set) {
        if ($this == $set) {
            return;
        }

        $curSize = count($this->words);
        while ($curSize > count($set->words)) {
            $this->words[--$curSize] = 0;
        }

        // Perform logical AND on words in common
        for ($i = 0; $i < count($set->words); $i++) {
            $this->words[$i] &= $set->words[$i];
        }
    }

    public function __toString(): string
    {
        return 'BITSET:__TO_STRING';
    }
}