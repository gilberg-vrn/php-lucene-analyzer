<?php

namespace ftIndex\analyses\morphology;

use ftIndex\io\BufferedReader;
use ftIndex\io\InputStreamReader;
use ftIndex\io\OutputStreamWriter;
use ftIndex\util\BitUtil;

/**
 * Class Morphology
 *
 * @package ftIndex\analyses\morphology
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/11/19 1:55 PM
 */
class Morphology
{

    /** @var int[][] */
    protected $separators;
    /** @var int[] */
    protected $rulesId;
    /** @var Heuristic[][] */
    protected $rules;
    /** @var string[] */
    protected $grammarInfo;
    /** @var LetterDecoderEncoder */
    protected $decoderEncoder;


    public static function MorphologyImplByFile(string $fileName, LetterDecoderEncoder $decoderEncoder)
    {
        $instance = new self([], [], [], []);
        $instance->readFromFile($fileName);
        $instance->setDecoderEncoder($decoderEncoder);

        return $instance;
    }

    public static function MorphologyImplByInputStream($inputStream, LetterDecoderEncoder $decoderEncoder)
    {
        $instance = new self([], [], [], []);
        $instance->readFromInputStream($inputStream);
        $instance->setDecoderEncoder($decoderEncoder);

        return $instance;
    }

    public function __construct(array $separators, array $rulesId, array $rules, array $grammarInfo)
    {
        $this->separators = $separators;
        $this->rulesId = $rulesId;
        $this->rules = $rules;
        $this->grammarInfo = $grammarInfo;
    }

    public function getNormalForms(string $s): array
    {
        $result = [];
        $ints = $this->decoderEncoder->encodeToArray($this->revertWord($s));

        $ruleId = $this->findRuleId($ints);
        $notSeenEmptyString = true;

        /** @var Heuristic $h */
        foreach ($this->rules[$this->rulesId[$ruleId]] as $h) {
            $e = (string) $h->transformWord($s);
            if (mb_strlen($e) > 0) {
                $result[] = $e;
            } elseif ($notSeenEmptyString) {
                $result[] = $s;
                $notSeenEmptyString = false;
            }
        }
        return $result;
    }

    public function getMorphInfo(string $s): array
    {
        $result = [];
        $ints = $this->decoderEncoder->encodeToArray($this->revertWord($s));
        $ruleId = $this->findRuleId($ints);

        /** @var Heuristic $h */
        foreach ($this->rules[$this->rulesId[$ruleId]] as $h) {
            $result[] = $h->transformWord($s) . "|" . (string)$this->grammarInfo[$h->getFormMorphInfo()];
        }
        return $result;
    }

    protected function findRuleId(array $ints): int
    {
        static $cache = [];

        $key = md5(json_encode($ints));

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $intsL = count($ints);

        $low = 0;
        $high = count($this->separators) - 1;
        $mid = 0;

        $found = false;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
//            $mid = ($low + $high);
//            $mid = BitUtil::uRShift($mid, 1);
            $midVal = $this->separators[$mid];

            $comResult = $this->compareToInts($ints, $midVal, $intsL);
            if ($comResult > 0) {
                $low = $mid + 1;
            } elseif ($comResult < 0) {
                $high = $mid - 1;
            } else {
                $found = true;
                break;
            }
        }

        if (($found && $comResult >= 0) || ($this->compareToInts($ints, $this->separators[$mid], $intsL) >= 0)) {
            return $cache[$key] = $mid;
        } else {
            return $cache[$key] = $mid - 1;
        }
    }

    private function compareToInts(array $i1, array $i2, $i1l): int
    {
        $minLength = min($i1l, count($i2));
        for ($i = 0; $i < $minLength; $i++) {
            $i3 = $i1[$i] < $i2[$i] ? -1 : ($i1[$i] == $i2[$i] ? 0 : 1);
            if ($i3 != 0) {
                return $i3;
            }
        }
        return $i1l - count($i2);
    }

    public function writeToFile(string $fileName)
    {
        $writer = new OutputStreamWriter(fopen($fileName, 'w'), "UTF-8"); // TODO: replace to string and file_put_contents
        $writer->write(count($this->separators) . "\n");
        /** @var int[] $i */
        foreach ($this->separators as $i) {
            $writer->write(count($i) . "\n");
            /** @var int $j */
            foreach ($i as $j) {
                $writer->write($j . "\n");
            }
        }
        /** @var int $i */
        foreach ($this->rulesId as $i) {
            $writer->write($i . "\n");
        }
        $writer->write(count($this->rules) . "\n");
        /** @var Heuristic[] $heuristics */
        foreach ($this->rules as $heuristics) {
            $writer->write(count($heuristics) . "\n");
            /** @var Heuristic $heuristic */
            foreach ($heuristics as $heuristic) {
                $writer->write((string)$heuristic . "\n");
            }
        }
        $writer->write(count($this->grammarInfo) . "\n");
        /** @var string $s */
        foreach ($this->grammarInfo as $s) {
            $writer->write($s . "\n");
        }
        $writer->close();
    }

    public function readFromFile(string $fileName)
    {
        $inputStream = fopen($fileName, 'rb');
        $this->readFromInputStream($inputStream);
    }

    /**
     * @param resource $inputStream
     */
    protected function readFromInputStream($inputStream)
    {
        $bufferedReader = new BufferedReader(new InputStreamReader($inputStream, "UTF-8"));
        $s = $bufferedReader->readLine();
        $amount = (int)$s;

        $this->readSeparators($bufferedReader, $amount);

        $this->readRulesId($bufferedReader, $amount);
        $this->readRules($bufferedReader);

        $this->readGrammaInfo($bufferedReader);
        $bufferedReader->close();
    }

    private function readGrammaInfo(BufferedReader $bufferedReader)
    {
        $s = $bufferedReader->readLine();
        $amount = (int)$s;
        $this->grammarInfo = [];
        for ($i = 0; $i < $amount; $i++) {
            $this->grammarInfo[$i] = $bufferedReader->readLine();
        }
    }

    protected function readRules(BufferedReader $bufferedReader)
    {
        $s = $bufferedReader->readLine();
        $amount = (int)$s;
        $this->rules = [];
        for ($i = 0; $i < $amount; $i++) {
            $s1 = $bufferedReader->readLine();
            $ruleLength = (int)$s1;
            $this->rules[$i] = [];
            for ($j = 0; $j < $ruleLength; $j++) {
                $this->rules[$i][$j] = new Heuristic($bufferedReader->readLine());
            }
        }
    }

    private function readRulesId(BufferedReader $bufferedReader, int $amount)
    {
        $this->rulesId = [];
        for ($i = 0; $i < $amount; $i++) {
            $s1 = $bufferedReader->readLine();
            $this->rulesId[$i] = (int)$s1;
        }
    }

    private function readSeparators(BufferedReader $bufferedReader, int $amount)
    {
        $this->separators = [];
        for ($i = 0; $i < $amount; $i++) {
            $s1 = $bufferedReader->readLine();
            $wordLength = (int)$s1;
            $this->separators[$i] = [];
            for ($j = 0; $j < $wordLength; $j++) {
                $this->separators[$i][$j] = (int)$bufferedReader->readLine();
            }
        }
    }

    protected function revertWord(string $s): string
    {
//        $result = '';
//        $cSequence = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
//        for ($i = 1; $i <= count($cSequence); $i++) {
//            $result .= $cSequence[count($cSequence) - $i];
//        }
//
//        return $result;

        $length   = mb_strlen($s);
        $reversed = '';
        while ($length-- > 0) {
            $reversed .= mb_substr($s, $length, 1);
        }

        return  $reversed;
    }

    /**
     * @param LetterDecoderEncoder $decoderEncoder
     */
    public function setDecoderEncoder(LetterDecoderEncoder $decoderEncoder)
    {
        $this->decoderEncoder = $decoderEncoder;
    }
}
