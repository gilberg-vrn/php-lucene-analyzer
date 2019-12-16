<?php

namespace ftIndex\util\automaton;

/**
 * Class StatePair
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/24/19 2:35 PM
 */
class StatePair
{
    public $s;
    public $s1;
    public $s2;


    /**
     * Constructs a new state pair.
     *
     * @param int $s1 first state
     * @param int $s2 second state
     */
    public function __construct(int $s = -1, int $s1, int $s2)
    {
        $this->s = $s;
        $this->s1 = $s1;
        $this->s2 = $s2;
    }

//  /**
//   * Checks for equality.
//   *
//   * @param obj object to compare with
//   * @return true if <tt>obj</tt> represents the same pair of states as this
//   *         pair
//   */
//  public boolean equals(Object obj) {
//    if (obj instanceof StatePair) {
//        StatePair p = (StatePair) obj;
//      return p.s1 == s1 && p.s2 == s2;
//    } else return false;
//}

//  /**
//   * Returns hash code.
//   *
//   * @return hash code
//   */
//  @Override
  public function hashCode(): int {
    // Don't use s1 ^ s2 since it's vulnerable to the case where s1 == s2 always --> hashCode = 0, e.g. if you call Operations.sameLanguage,
    // passing the same automaton against itself:
    return $this->s1 * 31 + $this->s2;
  }

    public function __toString()
    {
        return "StatePair(s1=" . $this->s1 . " s2=" . $this->s2 . ")";
    }
}