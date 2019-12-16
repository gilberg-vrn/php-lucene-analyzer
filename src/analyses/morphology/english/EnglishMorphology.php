<?php

namespace ftIndex\analyses\morphology\english;

use ftIndex\analyses\morphology\Morphology;

/**
 * Class EnglishMorphology
 *
 * @package ftIndex\analyses\morphology\english
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 12:52 PM
 */
class EnglishMorphology
    extends Morphology
{
    public function __construct()
    {
        parent::__construct([], [], [], []);
        $this->readFromInputStream(fopen(__DIR__ . '/morph.info', 'r'));
        $this->setDecoderEncoder(new EnglishLetterDecoderEncoder());
    }
}