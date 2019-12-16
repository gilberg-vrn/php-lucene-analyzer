<?php

namespace ftIndex\util\automaton;

use ftIndex\fst\Util;

/**
 * Class SortedIntSet
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/24/19 6:57 PM
 */
class SortedIntSet
{
    public $values = [];
    public $counts = [];
    public $upto;
    public $hashCode;

    // If we hold more than this many states, we switch from
    // O(N^2) linear ops to O(N log(N)) TreeMap
    const TREE_MAP_CUTOVER = 30;

    public $map = [];

    private $useTreeMap;

    public $state;

    public function __construct(int $capacity)
    {
        $this->values = []; //new int[capacity];
        $this->counts = []; //new int[capacity];
    }

// Adds this state to the set
    public function incr(int $num)
    {
        if ($this->useTreeMap) {
            $key = $num;
            $val = $this->map[$key] ?? null;
            if ($val == null) {
                $this->map[$key] = 1;
            } else {
                $this->map[$key] = 1 + $val;
            }
            return;
        }

        if ($this->upto == count($this->values)) {
            $this->values = Util::growIntArray($this->values, 1 + $this->upto);
            $this->counts = Util::growIntArray($this->counts, 1 + $this->upto);
        }

        for ($i = 0; $i < $this->upto; $i++) {
            if ($this->values[$i] == $num) {
                $this->counts[$i]++;
                return;
            } elseif ($num < $this->values[$i]) {
                // insert here
                $j = $this->upto - 1;
                while ($j >= $i) {
                    $this->values[1 + $j] = $this->values[$j];
                    $this->counts[1 + $j] = $this->counts[$j];
                    $j--;
                }
                $this->values[$i] = $num;
                $this->counts[$i] = 1;
                $this->upto++;
                return;
            }
        }

        // append
        $this->values[$this->upto] = $num;
        $this->counts[$this->upto] = 1;
        $this->upto++;

        if ($this->upto == self::TREE_MAP_CUTOVER) {
            $this->useTreeMap = true;
            for ($i = 0; $i < $this->upto; $i++) {
                $this->map[$this->values[$i]] = $this->counts[$i];
            }
        }
    }

    // Removes this state from the set, if count decrs to 0
    public function decr(int $num)
    {

        if ($this->useTreeMap) {
            $count = $this->map[$num] ?? null;
            if ($count == 1) {
                unset($this->map[$num]);
            } else {
                $this->map[$num] = $count - 1;
            }
            // Fall back to simple arrays once we touch zero again
            if (count($this->map) == 0) {
                $this->useTreeMap = false;
                $this->upto = 0;
            }
            return;
        }

        for ($i = 0; $i < $this->upto; $i++) {
            if ($this->values[$i] == $num) {
                $this->counts[$i]--;
                if ($this->counts[$i] == 0) {
                    $limit = $this->upto - 1;
                    while ($i < $limit) {
                        $this->values[$i] = $this->values[$i + 1];
                        $this->counts[$i] = $this->counts[$i + 1];
                        $i++;
                    }
                    $this->upto = $limit;
                }
                return;
            }
        }
//    assert false;
        if (true) {
            throw new \AssertionError('FALSE'); //HAHA
        }
    }

    public function computeHash()
    {
        if ($this->useTreeMap) {
            if (count($this->map) > count($this->values)) {
                $size = Util::oversize(count($this->map), 4);
//        $this->values = new int[size];
//        $this->counts = new int[size];
            }
            $hashCode = count($this->map);
            $this->upto = 0;
            foreach ($this->map as $state => $v) {
                $this->hashCode = 683 * $this->hashCode + $state;
                $this->values[$this->upto++] = $state;
            }
        } else {
            $this->hashCode = $this->upto;
            for ($i = 0; $i < $this->upto; $i++) {
                $this->hashCode = 683 * $this->hashCode + $this->values[$i];
            }
        }
    }

    public function freeze(int $state): FrozenIntSet
    {
        $c = []; //new int[upto];
        Util::arraycopy($this->values, 0, $c, 0, $this->upto);
        return FrozenIntSet::FrozenIniSetByValues($c, $this->hashCode, $this->state);
    }

    public function hashCode(): int
    {
        return $this->hashCode;
    }

    public function equals(SortedIntSet $_other): bool
    {
        if ($_other == null) {
            return false;
        }
        if (!($_other instanceof FrozenIntSet)) {
            return false;
        }
        $other = $_other;
        if ($this->hashCode != $other->hashCode) {
            return false;
        }
        if (count($other->values) != $this->upto) {
            return false;
        }
        for ($i = 0; $i < $this->upto; $i++) {
            if ($other->values[$i] != $this->values[$i]) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        $sb = '[';
        for ($i = 0; $i < $this->upto; $i++) {
            if ($i > 0) {
                $sb .= ' ';
            }
            $sb .= $this->values[$i] . ':' . $this->counts[$i];
        }
        $sb .= ']';
        return $sb;
    }
}