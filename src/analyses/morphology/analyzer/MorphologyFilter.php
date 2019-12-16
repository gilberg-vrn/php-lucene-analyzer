<?php

namespace ftIndex\analyses\morphology\analyzer;

use ftIndex\analyses\morphology\LuceneMorphology;
use ftIndex\analyses\TokenStream;

/**
 * Class MorphologyFilter
 *
 * @package ftIndex\analyses\morphology\analyzer
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 1:34 PM
 */
class MorphologyFilter extends TokenStream
{
    /** @var LuceneMorphology */
    private $luceneMorph;

    private $iterator;

//    protected $state = null;

    public function __construct(TokenStream $tokenStream, LuceneMorphology $luceneMorph)
    {
        parent::__construct($tokenStream);
        $this->luceneMorph = $luceneMorph;
    }


    final public function incrementToken(): bool
    {
        if ($this->iterator != null) {
            if (!empty($this->iterator)) {
                $this->restoreState($this->state);
                $this->posIncAttribute = 0;
                $tokens = array_splice($this->iterator, 0, 1);
                $this->termAttribute = reset($tokens);

                return true;
            } else {
                $this->state = null;
                $this->iterator = null;
            }
        }
        while (true) {
            if (!$this->input->incrementToken()) {
                return false;
            }
            if (!$this->keywordAttribute && mb_strlen($this->termAttribute) > 0) {
                $s = $this->termAttribute;
                if ($this->luceneMorph->checkString($s)) {
                    $forms = $this->luceneMorph->getNormalForms($s);
                    if (empty($forms)) {
                        continue;
                    } elseif (count($forms) == 1) {
                        $this->termAttribute = $forms[0];
                    } else {
                        $this->state = $this->captureState();
                        $this->iterator = $forms;
                        $tokens = array_splice($this->iterator, 0, 1);
                        $this->termAttribute = reset($tokens);
                    }
                }
            }

            return true;
        }
    }

    public function reset()
    {
//    parent::reset();
        $this->state = null;
    }
}
