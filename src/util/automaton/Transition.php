<?php

namespace ftIndex\util\automaton;

/**
 * Class Transition
 *
 * @package ftIndex\util\automaton
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/20/19 9:18 PM
 */
class Transition
{
    public $source;
    public $dest;
    public $min;
    public $max;
    public $transitionUpto = -1;

    public function __construct()
    {
    }

    public function __toString()
    {
        return $this->source . " --> " . $this->dest . " " . chr($this->min) . "-" . chr($this->max);
    }
}