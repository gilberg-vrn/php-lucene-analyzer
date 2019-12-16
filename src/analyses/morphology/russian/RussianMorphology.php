<?php

namespace ftIndex\analyses\morphology\russian;

use ftIndex\analyses\morphology\Morphology;

/**
 * Class RussianMorphology
 *
 * @package ftIndex\analyses\morphology\russian
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 8:36 PM
 */
class RussianMorphology
    extends Morphology
{
    public function __construct()
    {
        parent::__construct([], [], [], []);
        $this->readFromInputStream(fopen(__DIR__ . '/morph.info', 'r'));
        $this->setDecoderEncoder(new RussianLetterDecoderEncoder());
    }
}