<?php

namespace ftIndex\analyses\morphology;

use ftIndex\io\BufferedReader;

/**
 * Class LuceneMorphology
 *
 * @package ftIndex\analyses\morphology
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 3:44 PM
 */
class LuceneMorphology extends Morphology
{

    public static function LuceneMorphologyByFileName(string $fileName, LetterDecoderEncoder $decoderEncoder)
    {
        return parent::MorphologyImplByFile($fileName, $decoderEncoder);
    }

    public static function LuceneMorphologyByInputStream($inputStream, LetterDecoderEncoder $decoderEncoder)
    {
        return parent::MorphologyImplByInputStream($inputStream, $decoderEncoder);
    }

    protected function readRules(BufferedReader $bufferedReader)
    {
        $s = $bufferedReader->readLine();
        $amount = (int)$s;
        $this->rules = [];
        for ($i = 0; $i < $amount; $i++) {
            $s1 = $bufferedReader->readLine();
            $ruleLength = (int)$s1;
            $heuristics = [];
            for ($j = 0; $j < $ruleLength; $j++) {
                $heuristics[$j] = new Heuristic($bufferedReader->readLine());
            }
            $this->rules[$i] = $this->modifyHeuristic($heuristics);
        }
    }

    /**
     * @param Heuristic[] $heuristics
     *
     * @return array
     */
    private function modifyHeuristic(array $heuristics): array
    {
        /** @var Heuristic[] $result */
        $result = [];
        foreach ($heuristics as $heuristic) {
            $isAdded = true;
            foreach ($result as $ch) {
                $isAdded = $isAdded && !($ch->getActualNormalSuffix() === $heuristic->getActualNormalSuffix() && ($ch->getActualSuffixLength() == $heuristic->getActualSuffixLength()));
            }
            if ($isAdded) {
                $result[] = $heuristic;
            }
        }
        return $result;
    }

    public function checkString(String $s): bool
    {
        return $this->decoderEncoder->checkString($s);
    }
}
