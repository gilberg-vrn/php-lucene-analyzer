<?php

namespace ftIndex\util\automaton;

/**
 * Class CharacterRunAutomaton
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/25/19 2:36 PM
 */
class CharacterRunAutomaton extends RunAutomaton
{

    /**
     * Construct specifying maxDeterminizedStates.
     *
     * @param a Automaton to match
     * @param maxDeterminizedStates maximum number of states that the automataon
     *   can have once determinized.  If more states are required to determinize
     *   it then a TooComplexToDeterminizeException is thrown.
     */
    public function __construct(Automaton $a, int $maxDeterminizedStates = Operations::DEFAULT_MAX_DETERMINIZED_STATES)
    {
        parent::__construct($a, \IntlChar::CODEPOINT_MAX + 1, $maxDeterminizedStates);
    }

    /**
     * Returns true if the given string is accepted by this automaton.
     */
    public function run(string $s)
    {
        $p = 0;
        $l = mb_strlen($s);
        for ($i = 0, $cp = 0; $i < $l; $i += mb_strlen(\IntlChar::chr($cp))) {
            $p = $this->step($p, $cp = \IntlChar::ord(mb_substr($s, $i, 1)));
            if ($p == -1) {
                return false;
            }
        }
        return $this->accept[$p];
    }

    /**
     * Returns true if the given string is accepted by this automaton
     */
    public function runByParams($s, int $offset, int $length): bool
    {
        $p = 0;
        $l = $offset + $length;
        for ($i = $offset, $cp = 0; $i < $l; $i += mb_strlen(\IntlChar::chr($cp))) {
            $p = $this->step($p, $cp = \IntlChar::ord(mb_substr($s, $i, 1)));
            if ($p == -1) {
                return false;
            }
        }
        return $this->accept[$p];
    }


    public final function getInitialState(): int
    {
        return $this->initial;
    }
}
