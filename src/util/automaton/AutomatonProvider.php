<?php

namespace ftIndex\util\automaton;

/**
 * Class AutomatonProvider
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/20/19 9:11 PM
 */
interface AutomatonProvider
{
    public function getAutomaton(String $var1): Automaton;
}