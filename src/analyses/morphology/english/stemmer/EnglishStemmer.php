<?php

namespace ftIndex\analyses\morphology\english\stemmer;

use ftIndex\analyses\morphology\english\EnglishLuceneMorphology;

/**
 * Class EnglishStemmer
 *
 * @package ftIndex\analyses\morphology\english\stemmer
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/13/19 12:54 PM
 */
class EnglishStemmer
{
    /** @var EnglishLuceneMorphology */
    private $englishLuceneMorphology;

    public function __construct(EnglishLuceneMorphology $englishLuceneMorphology)
    {
        $this->englishLuceneMorphology = $englishLuceneMorphology;
    }

    public function getStemmedWord(string $word): string
    {
        if (!$this->englishLuceneMorphology->checkString($word)) {
            return $word;
        }
        $normalForms = $this->englishLuceneMorphology->getNormalForms($word);
        if (count($normalForms) == 1) {
            return $normalForms[0];
        }
        if ($key = array_search($word, $normalForms, true)) {
            unset($normalForms[$key]);
            $normalForms = array_values($normalForms);
        }
        if (count($normalForms) == 1) {
            return $normalForms[0];
        }
        return $word;
    }
}