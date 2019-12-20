<?php

namespace ftIndex\analyses\morphology\english;

use ftIndex\analyses\morphology\LuceneMorphology;

/**
 * Class EnglishLuceneMorphology
 *
 * @package ftIndex\analyses\morphology\english
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 12:51 PM
 */
class EnglishLuceneMorphology extends LuceneMorphology
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
        $this->setDecoderEncoder(new EnglishLetterDecoderEncoder());
    }
}