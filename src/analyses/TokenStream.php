<?php

namespace ftIndex\analyses;

/**
 * Class TokenStream
 *
 * @package ftIndex\analyses
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/5/19 3:18 PM
 */
abstract class TokenStream
{
    /** @var TokenStream */
    public $input;
    public $termAttribute = '';
    public $keywordAttribute = false;
    public $typeAttribute = 0;
    public $posIncAttribute = 1;
    public $offsetAttribute = [0, 0];
    public $posLenAttribute = 1;
    public $tagsAttribute = [];

    protected $state;

    private $attributes = [
        'termAttribute',
        'typeAttribute',
        'posIncAttribute',
        'offsetAttribute',
        'posLenAttribute',
        'tagsAttribute',
    ];

    public function __construct(TokenStream $input)
    {
        $this->input = $input;
        $this->termAttribute = &$input->termAttribute;
        $this->offsetAttribute = &$input->offsetAttribute;
        $this->posIncAttribute = &$input->posIncAttribute;
        $this->typeAttribute = &$input->typeAttribute;
        $this->posLenAttribute = &$input->posLenAttribute;
        $this->keywordAttribute = &$input->keywordAttribute;
        $this->tagsAttribute = &$input->tagsAttribute;
    }

    public abstract function incrementToken(): bool;

    public function correctOffset(int $currentOff): int
    {
//        return ($this->input instanceof CharFilter) ? $this->input->correctOffset($currentOff) : $currentOff;
        return $currentOff;
    }

    protected function captureState()
    {
//        if (isset($this->state[0])) {
//            return $this->state[0];
//        }
//        $this->state[0] = [
//            'attributeName' => null,
//            'attribute' => null,
//            'next' => null
//        ];
//
//        $s = &$this->state[0];
//
//        $c = &$s;
//
//        foreach ($this->attributes as $attribute) {
//            $c['attribute']  = $this->{$attribute};
//            $c['attributeName'] = $attribute;
//            $c['next'] = [
//                'attributeName' => null,
//                'attribute' => null,
//                'next' => null
//            ];
//            unset($c);
//            $c = &$c['next'];
//        }
//
//        return $s;
        return [
            'termAttribute' => $this->termAttribute,
            'typeAttribute' => $this->typeAttribute,
            'posIncAttribute' => $this->posIncAttribute,
            'offsetAttribute' => $this->offsetAttribute,
            'posLenAttribute' => $this->posLenAttribute,
            'keywordAttribute' => $this->keywordAttribute,
            'tagsAttribute' => $this->tagsAttribute,
        ];
    }

    protected function restoreState($state)
    {
//        do {
//            $this->{$state['attributeName']} = $state['attribute'];
//            $state = $state['next'];
//        } while($state !== null);


        $this->termAttribute = $state['termAttribute'];
        $this->typeAttribute = $state['typeAttribute'];
        $this->posIncAttribute = $state['posIncAttribute'];
        $this->offsetAttribute = $state['offsetAttribute'];
        $this->posLenAttribute = $state['posLenAttribute'];
        $this->keywordAttribute = $state['keywordAttribute'];
        $this->tagsAttribute = $state['tagsAttribute'];
    }

    public function clearAttributes()
    {
        $this->termAttribute = '';
        $this->keywordAttribute = false;
        $this->typeAttribute = 0;
        $this->posIncAttribute = 1;
        $this->offsetAttribute = [0, 0];
        $this->posLenAttribute = 1;
        $this->tagsAttribute = [];
    }

    public function end()
    {
        $this->input ? $this->input->end() : null;
    }
}