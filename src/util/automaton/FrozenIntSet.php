<?php

namespace ftIndex\util\automaton;

/**
 * Class FrozenIniSet
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/25/19 8:04 PM
 */
final class FrozenIntSet
{
    public $values = [];
    public $hashCode;
    public $state;

    public static function FrozenIniSetByValues($values, int $hashCode, int $state)
    {
        $instance = new self(0, 0);

        $instance->values = $values;
        $instance->hashCode = $hashCode;
        $instance->state = $state;

        return $instance;
    }

    public function __construct(int $num, int $state)
    {
        $this->values = []; //new int[] {num};
        $this->state = $state;
        $this->hashCode = 683 + $num;
    }

    public function hashCode(): int
    {
        return $this->hashCode;
    }

    public function equals(FrozenIntSet $_other): bool
    {
        if ($_other == null) {
            return false;
        }
        if ($_other instanceof FrozenIntSet) {
            $other = $_other;
            if ($this->hashCode != $other->hashCode) {
                return false;
            }
            if (count($other->values) != count($this->values)) {
                return false;
            }
            for ($i = 0; $i < count($this->values); $i++) {
                if ($other->values[$i] != $this->values[$i]) {
                    return false;
                }
            }
            return true;
        } elseif ($_other instanceof SortedIntSet) {
            $other = $_other;
            if ($this->hashCode != $other->hashCode) {
                return false;
            }
            if (count($other->values) != count($this->values)) {
                return false;
            }
            for ($i = 0; $i < count($this->values); $i++) {
                if ($other->values[$i] != $this->values[$i]) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    public function __toString(): string
    {
        $sb = '[';
        for ($i = 0; $i < count($this->values); $i++) {
            if ($i > 0) {
                $sb .= ' ';
            }
            $sb .= $this->values[$i];
        }
        $sb .= ']';
        return $sb;
    }
}