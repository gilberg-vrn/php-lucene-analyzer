<?php

namespace ftIndex\analyses\morphology\russian;

use ftIndex\analyses\morphology\LuceneMorphology;

/**
 * Class RussianLuceneMorphology
 *
 * @package ftIndex\analyses\morphology\russian
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 8:24 PM
 */
class RussianLuceneMorphology
    extends LuceneMorphology
{
    public static function getInstance()
    {
        static $instance;

        if ($instance === null) {
            $instance = new self();
        }

        return $instance;
    }

    public function __construct()
    {
        parent::__construct([], [], [], []);
        $this->readFromInputStream(fopen(__DIR__ . '/morph.info', 'r'));
        $this->setDecoderEncoder(new RussianLetterDecoderEncoder());
    }
}