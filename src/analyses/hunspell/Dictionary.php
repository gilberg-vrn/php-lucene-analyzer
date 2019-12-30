<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\fst\CharSequenceOutputs;
use ftIndex\fst\IntSequenceOutputs;
use ftIndex\fst\Util;
use ftIndex\store\ByteArrayDataOutput;
use ftIndex\util\automaton\CharacterRunAutomaton;
use ftIndex\util\automaton\RegExp;

class Dictionary
{

    const NOFLAGS = [];

    const ALIAS_KEY           = 'AF';
    const MORPH_ALIAS_KEY     = 'AM';
    const PREFIX_KEY          = 'PFX';
    const SUFFIX_KEY          = 'SFX';
    const FLAG_KEY            = 'FLAG';
    const COMPLEXPREFIXES_KEY = 'COMPLEXPREFIXES';
    const CIRCUMFIX_KEY       = 'CIRCUMFIX';
    const IGNORE_KEY          = 'IGNORE';
    const ICONV_KEY           = 'ICONV';
    const OCONV_KEY           = 'OCONV';
    const FULLSTRIP_KEY       = 'FULLSTRIP';
    const LANG_KEY            = 'LANG';
    const KEEPCASE_KEY        = 'KEEPCASE';
    const NEEDAFFIX_KEY       = 'NEEDAFFIX';
    const PSEUDOROOT_KEY      = 'PSEUDOROOT';
    const ONLYINCOMPOUND_KEY  = 'ONLYINCOMPOUND';

    const NUM_FLAG_TYPE  = 'num';
    const UTF8_FLAG_TYPE = 'UTF-8';
    const LONG_FLAG_TYPE = 'long';

    // TODO: really for suffixes we should reverse the automaton and run them backwards
    const PREFIX_CONDITION_REGEX_PATTERN = '%s.*';
    const SUFFIX_CONDITION_REGEX_PATTERN = '.*%s';

    const SHORT_MAX_VALUE     = 32767;
    const CHARACTER_MAX_VALUE = 65535;

//FST<IntsRef> prefixes;
//FST<IntsRef> suffixes;

    /** @var FST */
    public $prefixes = [];
    public $prefixesCache = [];

    /** @var FST */
    public $suffixes = [];
    public $suffixesCache = [];

    // all condition checks used by prefixes and suffixes. these are typically re-used across
    // many affix stripping rules. so these are deduplicated, to save RAM.
//ArrayList<CharacterRunAutomaton> patterns = new ArrayList<>();
    /** @var CharacterRunAutomaton[] */
    public $patterns = [];

    // the entries in the .dic file, mapping to their set of flags.
    // the fst output is the ordinal list for flagLookup
//FST<IntsRef> words;
    /** @var array|SimpleFST|FST */
    protected $words;

    // the list of unique flagsets (wordforms). theoretically huge, but practically
    // small (e.g. for polish this is 756), otherwise humans wouldn't be able to deal with it either.
//BytesRefHash flagLookup = new BytesRefHash();
    public $flagLookup = [];

    // the list of unique strip affixes.
//char[] stripData;
//int[] stripOffsets;
    public $stripData = [];
    public $stripOffsets = [];

    // 8 bytes per affix
    public $affixData = []; // byte array [64]
    private $currentAffix = 0;

    /** @var FlagParsingStrategy */
    private $flagParsingStrategy/* = new SimpleFlagParsingStrategy()*/
    ; // Default flag parsing strategy

    /** @var string[] */
    private $aliases = [];
    private $aliasCount = 0;

    // AM entries
    /** @var string[] */
    private $morphAliases;
    private $morphAliasCount = 0;

    // st: morphological entries (either directly, or aliased from AM)
    /** @var string[] 8 items */
    private $stemExceptions = [];
    private $stemExceptionCount = 0;
    // we set this during sorting, so we know to add an extra FST output.
    // when set, some words have exceptional stems, and the last entry is a pointer to stemExceptions

    /** @var bool */
    public $hasStemExceptions;

    private $tempDir = '/tmp'; // TODO: make this configurable?

    /** @var bool */
    public $ignoreCase;
    /** @var boolean */
    public $complexPrefixes;
    /** @var boolean */
    public $twoStageAffix; // if no affixes have continuation classes, no need to do 2-level affix stripping

    /** @var int */
    public $circumfix = -1; // circumfix flag, or -1 if one is not defined
    /** @var int */
    public $keepcase = -1;  // keepcase flag, or -1 if one is not defined
    /** @var int */
    public $needaffix = -1; // needaffix flag, or -1 if one is not defined
    /** @var int */
    public $onlyincompound = -1; // onlyincompound flag, or -1 if one is not defined

    // ignored characters (dictionary, affix, inputs)
    /** @var string[] Origin type char[] */
    private $ignore = [];

    // FSTs used for ICONV/OCONV, output ord pointing to replacement text
//FST<CharsRef> iconv;
//FST<CharsRef> oconv;
    public $iconv;
    public $oconv;

    /** @var boolean */
    public $needsInputCleaning;
    /** @var boolean */
    public $needsOutputCleaning;

    // true if we can strip suffixes "down to nothing"
    /** @var bool */
    public $fullStrip;

    // language declaration of the dictionary
    /** @var string */
    protected $language;
    // true if case algorithms should use alternate (Turkish/Azeri) mapping
    /** @var bool */
    protected $alternateCasing;

    private $affixEncoding = 'UTF-8';

    private function createTempFile($path, $name, $extension)
    {
        $uniq = md5($name . $extension . microtime(true));

        return sprintf('%s/%s.%s.%s', $path, $name, $uniq, $extension);
    }

    /**
     * Creates a new Dictionary containing the information read from the provided InputStreams to src affix
     * and dictionary files.
     * You have to close the provided InputStreams yourself.
     *
     * @param resource   $affix        for reading the src affix file (won't be closed).
     * @param resource[] $dictionaries for reading the src dictionary files (won't be closed).
     * @param bool       $ignoreCase
     *
     * //     * @throws IOException Can be thrown while reading from the InputStreams
     * //     * @throws ParseException Can be thrown if the content of the files does not meet expected formats
     */
    public function __construct($affix, array $dictionaries, $ignoreCase = false)
    {
        $this->flagParsingStrategy = new SimpleFlagParsingStrategy();
        $this->ignoreCase = $ignoreCase;
        $this->needsInputCleaning = $ignoreCase;
        $this->needsOutputCleaning = false; // set if we have an OCONV
        $this->flagLookup[] = ''; // no flags -> ord 0
        $this->affixData = array_fill(0, 64, 0);

        $aff = $this->createTempFile($this->tempDir, "affix", "aff");;
        $out = fopen($aff, 'w+');

        /** @var resource aff1 */
        $aff1 = null;
        /** @var resource aff2 */
        $aff2 = null;

        $success = false;
        try {
            // copy contents of affix stream to temp file
            $buffer = ''; //new byte [1024 * 8];

            while (!feof($affix)) {
                $buffer = fread($affix, 8192);
                fwrite($out, $buffer);
            }
            fclose($out);

            $timer = microtime(1);
            // pass 1: get encoding
            $aff1 = fopen($aff, 'r+');
            $encoding = $this->getDictionaryEncoding($aff1);
//            $encoding = 'utf-8';

            // pass 2: parse affixes
            $decoder = $this->getJavaEncoding($encoding);
            $aff2 = fopen($aff, 'r+');
            $this->readAffixFile($aff2, $decoder);

            error_log('readAffixFile: ' . (microtime(1) - $timer));
            $timer = microtime(1);
            // read dictionary entries
//            /** @var IntSequenceOutputs $o */
//            $o = IntSequenceOutputs::getSingleton();

//            /** @var Builder<IntsRef> $b */
//            $b = new Builder('FST.INPUT_TYPE.BYTE4', $o);
            $b = [];
            $this->readDictionaryFiles($dictionaries, $decoder, $b);

            error_log('readDictionaryFile: ' . (microtime(1) - $timer));
//            die();

//            $this->words = $this->affixFST($b);
            $timer = microtime(1);
            $this->words = $this->calqueFST($b);
//            $this->words = $this->simpleFST($b);
            error_log('prepareFST: ' . (microtime(1) - $timer));
//            $this->words = $b; //$this->affixFST($b);

            $this->aliases = null; // no longer needed
            $this->morphAliases = null; // no longer needed
            $success = true;
        } finally {
            fclose($aff1);
            fclose($aff2);

            if ($success) {
                unlink($aff);
            } else {
                unlink($aff);
            }
        }
    }

    protected function calqueFST($words)
    {
        $filename = '/tmp/calque.' . md5(json_encode($words)) . '.fst';
        $optionsStorage = [];
        if (!file_exists($filename)) {
            $w = fopen($filename, 'w+');
            $builder = \calque\builder::newBuilder($w);

            $counter = 0;
            foreach ($words as $word => $options) {
                $builder->Insert($word, $counter);
                $optionsStorage[$counter++] = $options;
            }
            $builder->Close();
        } else {
            $counter = 0;
            foreach ($words as $word => $options) {
                $optionsStorage[$counter++] = $options;
            }
        }

        $fst = new \calque\fst(file_get_contents($filename));

        return [
            'options' => $optionsStorage,
            'fst' => $fst
        ];
    }

    protected function simpleFST($words)
    {
        $fst = new SimpleFST();

        foreach ($words as $word => $options) {
            $fst->addWord($word, $options);
        }

        return $fst;
    }

    /**
     * Looks up Hunspell word forms from the dictionary
     *
     * @param string $word
     * @param int    $offset
     * @param int    $length
     *
     * @return string|array
     */
    public function lookupWord($word, $offset, $length)
    {
//        return $this->lookup($this->words, $word, $offset, $length);
        return $this->lookupCalque($this->words['fst'], $this->words['options'], $word);
//        return $this->lookupSimpleFst($this->words, $word, $offset, $length);
//        return $this->lookupFst($this->words, $word, $offset, $length);
    }

    // only for testing
    public function lookupPrefix($word, $offset, $length)
    {
        return $this->lookup($this->prefixes, $word, $offset, $length);
    }

    // only for testing
    public function lookupSuffix($word, $offset, $length)
    {
        return $this->lookup($this->suffixes, $word, $offset, $length);
    }

    protected function lookupCalque(\calque\fst $fst, $options, $word)
    {
        list($result, $status, $err) = $fst->Get($word);
        if (!$status) {
            return null;
        }

        return $options[$result] ?? null;
    }

    protected function lookupSimpleFst(SimpleFST $words, $word, $offset, $length)
    {
        return $words->lookupWord($word);
    }

    protected function lookup($words, $word, $offset, $length)
    {
        $limit = $offset + $length;
        if (is_array($word)) {
            $word = implode('', $word);
        }
        if (isset($words[$word])) {
            return $words[$word];
        }

        $wordLookup = '';
        for ($i = $offset; $i < $limit; $i++) {
            $wordLookup .= mb_substr($word, $i, 1);
            $foundPart = false;

            foreach ($this->words as $dictWord => $dictOutput) {
                if (preg_match("/^{$wordLookup}/", $dictWord) > 0) {
                    $foundPart = true;
                    break;
                }
            }
            if (!$foundPart) {
                break;
            }
        }

        if (!isset($words[$wordLookup])) {
            return null;
        }

        return $words[$wordLookup];
    }

    protected function lookupFst(FST $fst, $word, $offset, $length)
    {
        $bytesReader = $fst->getBytesReader();

        /** @var Arc $arc */
        $arc = $fst->getFirstArc(new Arc());

        // Accumulate output as we go
        $NO_OUTPUT = 'no_output';
        $output = $NO_OUTPUT;

        $limit = $offset + $length;
        try {
            for ($i = $offset; $i < $limit; $i++) {
                $cp = \IntlChar::ord($word[$i]);
                if ($fst->findTargetArc($cp, $arc, $arc, $bytesReader) == null) {
                    return null;
                } elseif ($arc->output != $NO_OUTPUT) {
                    $output = $fst->outputs->add($output, $arc->output); // mb. fstOutputs - class, and add - static method
                }
            }
            if ($fst->findTargetArc(FST::END_LABEL, $arc, $arc, $bytesReader) == null) { // FST.END_LABEL = -1
                return null;
            } elseif ($arc->output != $NO_OUTPUT) {
                return $fst->outputs->add($output, $arc->output);
            } else {
                return $output;
            }
        } catch (IOException $bogus) {
            throw new \RuntimeException("", 0, $bogus);
        }
    }

    private function codePointAt($word, $index, $limit)
    {
        $points = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);

        if (!isset($points[$index])) {
            throw new IndexOutOfBoundException('Index out of bound!');
        }

        return ord($points[$index]);
    }

    /**
     * Reads the affix file through the provided InputStream, building up the prefix and suffix maps
     *
     * @param resource $affixStream to read the content of the affix file from
     * @param null     $decoder     to decode the content of the file
     *
     * @throws IOException Can be thrown while reading from the InputStream
     * @throws ParseException
     * @throws IllegalArgumentException
     * @throws UnsupportedOperationException
     */
    private function readAffixFile($affixStream, $decoder)
    {
        $prefixes = []; //new TreeMap<>();
        $suffixes = []; //new TreeMap<>();

        // zero condition -> 0 ord
        $seenPatterns['.*'] = 0;
        $this->patterns[] = null;

        // zero strip -> 0 ord
        $seenStrips = []; //new LinkedHashMap<>();
        $seenStrips[''] = 0;

//    LineNumberReader reader = new LineNumberReader(new InputStreamReader($affixStream, $decoder));
        $line = null;
        $firstLine = true;
        $lineNumber = 0;
        while (($line = fgets($affixStream)) != null) {
            $line = trim($line);
            $line = iconv($decoder, 'utf-8', $line);
            $lineNumber++;
            // ignore any BOM marker on first line
            if ($firstLine && mb_strpos($line, "\u{FEFF}") === 0) {
                $line = mb_substr($line, 1);
                $firstLine = false;
            }
            if (mb_strpos($line, self::ALIAS_KEY) === 0) {
                $this->parseAlias($line);
            } elseif (mb_strpos($line, self::MORPH_ALIAS_KEY) === 0) {
                $this->parseMorphAlias($line);
            } elseif (mb_strpos($line, self::PREFIX_KEY) === 0) {
                $this->parseAffix($prefixes, $line, $affixStream/*$reader*/, self::PREFIX_CONDITION_REGEX_PATTERN, $seenPatterns, $seenStrips, $decoder);
            } elseif (mb_strpos($line, self::SUFFIX_KEY) === 0) {
                $this->parseAffix($suffixes, $line, $affixStream/*$reader*/, self::SUFFIX_CONDITION_REGEX_PATTERN, $seenPatterns, $seenStrips, $decoder);
            } elseif (mb_strpos($line, self::FLAG_KEY) === 0) {
                // Assume that the FLAG line comes before any prefix or suffixes
                // Store the strategy so it can be used when parsing the dic file
                $this->flagParsingStrategy = $this->getFlagParsingStrategy($line);
            } elseif ($line === self::COMPLEXPREFIXES_KEY) {
                $this->complexPrefixes = true; // 2-stage prefix+1-stage suffix instead of 2-stage suffix+1-stage prefix
            } elseif (mb_strpos($line, self::CIRCUMFIX_KEY) === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) != 2) {
                    throw new ParseException("Illegal CIRCUMFIX declaration. Line: {$lineNumber}");
                }
                $this->circumfix = $this->flagParsingStrategy->parseFlag($parts[1]);
            } elseif (mb_strpos($line, self::KEEPCASE_KEY) === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) != 2) {
                    throw new ParseException("Illegal KEEPCASE declaration. Line: {$lineNumber}");
                }
                $this->keepcase = $this->flagParsingStrategy->parseFlag($parts[1]);
            } elseif (mb_strpos($line, self::NEEDAFFIX_KEY) === 0 || mb_strpos($line, self::PSEUDOROOT_KEY) === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) != 2) {
                    throw new ParseException("Illegal NEEDAFFIX declaration. Line: {$lineNumber}");
                }
                $this->needaffix = $this->flagParsingStrategy->parseFlag($parts[1]);
            } elseif (mb_strpos($line, self::ONLYINCOMPOUND_KEY) === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) != 2) {
                    throw new ParseException("Illegal ONLYINCOMPOUND declaration. Line: {$lineNumber}");
                }
                $this->onlyincompound = $this->flagParsingStrategy->parseFlag($parts[1]);
            } elseif (mb_strpos($line, self::IGNORE_KEY) === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) != 2) {
                    throw new ParseException("Illegal IGNORE declaration. Line: {$lineNumber}");
                }
                $this->ignore = preg_split('//u', $parts[1], -1, PREG_SPLIT_NO_EMPTY);
                $this->ignore = array_flip($this->ignore);
                $this->needsInputCleaning = true;
            } elseif (mb_strpos($line, self::ICONV_KEY) === 0 || mb_strpos($line, self::OCONV_KEY) === 0) {
                $parts = preg_split('/\s+/', $line);
                $type = $parts[0];
                if (count($parts) != 2) {
                    throw new ParseException("Illegal {$type} declaration. Line: {$lineNumber}");
                }
                $num = (int)$parts[1];
                $res = $this->parseConversions($affixStream/*$reader*/, $num);
                if ($type === "ICONV") {
                    $this->iconv = $res;
                    $this->needsInputCleaning = $this->iconv != null;
                } else {
                    $this->oconv = $res;
                    $this->needsOutputCleaning = $this->oconv != null;
                }
            } elseif (mb_strpos($line, self::FULLSTRIP_KEY) === 0) {
                $this->fullStrip = true;
            } elseif (mb_strpos($line, self::LANG_KEY) === 0) {
                $this->language = trim(mb_substr($line, mb_strlen(self::LANG_KEY)));
                $this->alternateCasing = "tr_TR" == $this->language || "az_AZ" == $this->language;
            }
        }

        $this->prefixes = $prefixes; //$this->affixFST($prefixes);
        $this->suffixes = $suffixes; //$this->affixFST($suffixes);

        $totalChars = 0;
        foreach ($seenStrips as $strip => $v) {
            $totalChars += mb_strlen($strip);
        }

        $this->stripData = []; // new char[totalChars]
        $this->stripOffsets = []; //new int[seenStrips.size()+1];
        $currentOffset = 0;
        $currentIndex = 0;

        foreach ($seenStrips as $strip => $v) {
            $this->stripOffsets[$currentIndex++] = $currentOffset;
//        $strip.getChars(0, $strip.length(), $stripData, $currentOffset); // copy chars  from $strip from 0 to $strip.length() to $stripData at $currentOffset
            $currentOffset += mb_strlen($strip);
        }
        $this->stripData = preg_split("//u", implode('', array_keys($seenStrips)), -1, PREG_SPLIT_NO_EMPTY);

        if (!($currentIndex == count($seenStrips))) {
            throw new \AssertionError("Current index [{$currentIndex}] != count of seen strips: " . count($seenStrips));
        }
        $this->stripOffsets[$currentIndex] = $currentOffset;
    }

    protected static function escapeDash($re)
    {
        // we have to be careful, even though dash doesn't have a special meaning,
        // some dictionaries already escape it (e.g. pt_PT), so we don't want to nullify it
        $escaped = '';
        $re = preg_split('//u', $re, -1, PREG_SPLIT_NO_EMPTY);
        for ($i = 0; $i < count($re); $i++) {
            $c = $re[$i];
            if ($c == '-') {
                $escaped .= "\\-";
            } else {
                $escaped .= $c;
                if ($c == '\\' && $i + 1 < mb_strlen($re)) {
                    $escaped .= ($re[$i + 1]);
                    $i++;
                }
            }
        }
        return $escaped;
    }

    /**
     * @param array $affixes
     *
     * @return FST
     */
    private function affixFST($affixes)
    {
//        return $affixes;
        uksort($affixes, function($a, $b) {
            Util::toUTF16($a, $a1);
            Util::toUTF16($b, $b1);

            return Util::array_cmp($a1, $b1);
        });

//        var_dump($affixes);
//        $affixes = []; //TODO: REMOVE!!! AND FIX!!!!

        $outputs = IntSequenceOutputs::getSingleton();
        $builder = Builder::newBuilder(FST::INPUT_TYPE_BYTE4, $outputs);
        foreach ($affixes as $key => $entries) {
            Util::toUTF16($key, $scratch);
            $output = [];
            foreach ($entries as $c) {
                $output[] = $c;
            }
            var_dump($key, $scratch, $output);
            $builder->add($scratch, $output);
        }
        return $builder->finish();
    }

    /**
     * Parses a specific affix rule putting the result into the provided affix map
     *
     * @param array    $affixes          Map where the result of the parsing will be put
     * @param string   $header           Header line of the affix rule
     * @param resource $reader           BufferedReader to read the content of the rule from
     * @param String   $conditionPattern {@link String#format(String, Object...)} pattern to be used to generate the condition regex
     *                                   pattern
     * @param array    $seenPatterns     map from condition -&gt; index of patterns, for deduplication.
     * @param array    $seenStrips
     *
     * @throws IllegalArgumentException
     * @throws ParseException
     * @throws UnsupportedOperationException
     */
    private function parseAffix(&$affixes, $header, $reader, $conditionPattern, &$seenPatterns, &$seenStrips, $decoder)
    {
        $scratch = [];
        $sb = '';
        $args = preg_split('/\s+/ui', $header);

        $crossProduct = $args[2] == "Y";
        $isSuffix = $conditionPattern == self::SUFFIX_CONDITION_REGEX_PATTERN;

        $numLines = (int)$args[3];
        if (count($this->affixData) < ($this->currentAffix << 3) + ($numLines << 3)) {
            $this->affixData = Util::growByteArray($this->affixData, ($this->currentAffix << 3) + ($numLines << 3));
        }
        $affixWriter = new ByteArrayDataOutput($this->affixData, $this->currentAffix << 3, $numLines << 3);
//        $affixWriterPos = $this->currentAffix << 2;

        $lineCounter = 0;
        for ($i = 0; $i < $numLines; $i++) {
            $lineCounter++;
//        assert affixWriter.getPosition() == currentAffix << 3;
            if ($affixWriter->getPosition() != $this->currentAffix << 3) {
                var_dump($affixWriter->getPosition(), $this->currentAffix, $this->currentAffix << 2);
                throw new \AssertionError('NOT: affixWriter.getPosition() == currentAffix << 3');
            }
            $line = trim(fgets($reader));
            $line = iconv($decoder, 'utf-8', $line);
            $ruleArgs = preg_split('/\s+/', $line);

            // from the manpage: PFX flag stripping prefix [condition [morphological_fields...]]
            // condition is optional
            if (count($ruleArgs) < 4) {
                throw new ParseException("The affix file contains a rule with less than four elements: [{$line}] // Line: {$lineCounter}");
            }

            $flag = $this->flagParsingStrategy::parseFlag($ruleArgs[1]);
            $strip = $ruleArgs[2] == '0' ? '' : $ruleArgs[2];
            $affixArg = $ruleArgs[3];
            $appendFlags = null;

            // first: parse continuation classes out of affix
            $flagSep = strrpos($affixArg, '/');
//            $flagSep = $affixArg.lastIndexOf('/');
            if ($flagSep !== false) {
                $flagPart = mb_substr($affixArg, $flagSep + 1);
                $affixArg = mb_substr($affixArg, 0, $flagSep);

                if ($this->aliasCount > 0) {
                    $flagPart = $this->getAliasValue((int)$flagPart);
                }

                $appendFlags = $this->flagParsingStrategy::parseFlags($flagPart);
                sort($appendFlags);
                $this->twoStageAffix = true;
            }
            // zero affix -> empty string
            if ("0" == $affixArg) {
                $affixArg = "";
            }

            $condition = count($ruleArgs) > 4 ? $ruleArgs[4] : '.';
            // at least the gascon affix file has this issue
            if (mb_strpos($condition, '[') === 0 && mb_strrpos($condition, ']') === false) {
                var_dump($condition);
                $condition .= ']';
            }

            // "dash hasn't got special meaning" (we must escape it)
            if (mb_strpos($condition, '-') >= 0) {
                $condition = $this->escapeDash($condition);
            }

            if ("." == $condition) {
                $regex = ".*"; // Zero condition is indicated by dot
            } elseif ($condition == $strip) {
                $regex = ".*"; // TODO: optimize this better:
                // if we remove 'strip' from condition, we don't have to append 'strip' to check it...!
                // but this is complicated...
            } else {
                $regex = sprintf($conditionPattern, $condition);
            }

            // deduplicate patterns
            $patternIndex = isset($seenPatterns[$regex]) ? $seenPatterns[$regex] : null;
            if ($patternIndex === null) {
                $patternIndex = count($this->patterns);
                if ($patternIndex > self::SHORT_MAX_VALUE) {
                    throw new UnsupportedOperationException("Too many patterns, please report this to dev@lucene.apache.org");
                }
                $seenPatterns[$regex] = $patternIndex;
                $pattern = '/' . $regex . '$/';
//                error_log("{$patternIndex} -> {$pattern}");
//                $regexp = (new RegExp($regex));
//                error_log('regexp');
//                $automaton = $regexp->toAutomaton();
//                error_log('automaton');
                $this->patterns[] = $pattern; //new CharacterRunAutomaton($automaton);
            }

            $stripOrd = isset($seenStrips[$strip]) ? $seenStrips[$strip] : null;
            if ($stripOrd === null) {
                $stripOrd = count($seenStrips);
                $seenStrips[$strip] = $stripOrd;
                if ($stripOrd > self::CHARACTER_MAX_VALUE) {
                    throw new UnsupportedOperationException("Too many unique strips, please report this to dev@lucene.apache.org");
                }
            }

            if ($appendFlags === null) {
                $appendFlags = self::NOFLAGS;
            }

            $this->encodeFlags($scratch, $appendFlags);
            $v = implode('', $scratch);


            $appendFlagsOrd = array_search($v, $this->flagLookup, true);
            if ($appendFlagsOrd === false) {
                $this->flagLookup[] = $v;
                $appendFlagsOrd = count($this->flagLookup) - 1;
            }

            $affixWriter->writeShort(ord($flag));
            $affixWriter->writeShort($stripOrd);
            // encode crossProduct into patternIndex
            $patternOrd = (int)$patternIndex << 1 | ($crossProduct ? 1 : 0);
            $affixWriter->writeShort((int)$patternOrd);
            $affixWriter->writeShort((int)$appendFlagsOrd);

            if ($this->needsInputCleaning) {
                $cleaned = $this->cleanInput($affixArg, $sb);
                $affixArg = implode('', $cleaned);
            }

            if ($isSuffix) {
                $affixArgParts = is_string($affixArg) ? preg_split('//u', $affixArg, -1, PREG_SPLIT_NO_EMPTY) : $affixArg;
                $affixArg = implode('', array_reverse($affixArgParts));
            }

            if (!isset($affixes[$affixArg])) {
                $affixes[$affixArg] = [];
            }
            $affixes[$affixArg][] = $this->currentAffix;
            $this->currentAffix++;
        }
    }

    /**
     * @param resource $reader
     * @param int      $num
     *
     * @return array|SimpleFST
     * @throws ParseException
     */
    private function parseConversions($reader, $num)
    {
        $mappings = [];

        $lineNumber = 0;
        for ($i = 0; $i < $num; $i++) {
            $lineNumber++;
            $line = trim(fgets($reader));
            $parts = preg_split('/\s+/', $line);
            if (count($parts) != 3) {
                throw new ParseException("invalid syntax: [{$line}] // Line: {$lineNumber}");
            }

            if (isset($mappings[$parts[1]])) {
                throw new IllegalStateException("duplicate mapping specified for: {$parts[1]}");
            }

//            if ($parts[1] === 'ʼ' || $parts[1] === '’') {
//                $parts[1] = '\'';
//            }

            if ($parts[2] === '0') {
                $mappings[$parts[1]] = false;
            } else {
                $mappings[$parts[1]] = $parts[2];
            }
        }
        $mappings = array_filter($mappings);

//        $res = new SimpleFST();
//        foreach ($mappings as $word => $replace) {
//            $replace = preg_split('//u', $replace, -1, PREG_SPLIT_NO_EMPTY);
//            $res->addWord($word, $replace);
//        }
//
//        return $res;


        $fileName = '/tmp/calque.' . md5(json_encode($mappings)) . '.fst';

        $replaceArray = [];
        if (!file_exists($fileName)) {
            $w = fopen($fileName, 'w+');
            $builder = \calque\builder::newBuilder($w);

            $counter = 0;
            foreach ($mappings as $word => $replace) {
                $builder->Insert($word, $counter);
                $replaceArray[$counter++] = $replace;
            }
            $builder->Close();
        } else {
            $counter = 0;
            foreach ($mappings as $word => $replace) {
                $replaceArray[$counter++] = $replace;
            }
        }

        $fst = new \calque\fst(file_get_contents($fileName));

        return [
            'replace' => $replaceArray,
            'fst' => $fst
        ];
    }

    /** pattern accepts optional BOM + SET + any whitespace */
    const ENCODING_PATTERN = "/^(\u{00EF}\u{00BB}\u{00BF})?SET\s+/";

    /**
     * Parses the encoding specified in the affix file readable through the provided InputStream
     *
     * @param resource $affix InputStream for reading the affix file
     *
     * @return string Encoding specified in the affix file
     * @throws IOException Can be thrown while reading from the InputStream
     * @throws ParseException Thrown if the first non-empty non-comment line read from the file does not adhere to the format {@code SET <encoding>}
     */
    static public function getDictionaryEncoding($affix)
    {
        $encoding = '';
        for (; ;) {
            while (($ch = fread($affix, 1)) !== false) {
                if ($ch == "\n") {
                    break;
                }
                if ($ch != "\r") {
                    $encoding .= $ch;
                }
            }
            if (
                strlen($encoding) == 0 || $encoding[0] == '#' ||
                // this test only at the end as ineffective but would allow lines only containing spaces:
                strlen(trim($encoding)) == 0
            ) {
                if ($ch == false) {
                    throw new ParseException("Unexpected end of affix file.", 0);
                }
                continue;
            }

            if (preg_match(self::ENCODING_PATTERN, $encoding, $matches)) {
                return mb_substr($encoding, strlen($matches[0]));
            }
        }
    }

    static protected $CHARSET_ALIASES = [
        'microsoft-cp1251' => 'windows-1251',
        'TIS620-2533' => 'TIS-620'
    ];

    /**
     * Retrieves the CharsetDecoder for the given encoding.  Note, This isn't perfect as I think ISCII-DEVANAGARI and
     * MICROSOFT-CP1251 etc are allowed...
     *
     * @param string $encoding Encoding to retrieve the CharsetDecoder for
     *
     * @return CharSetDecoder for the given encoding
     */
    private function getJavaEncoding($encoding)
    {
//        if ("ISO8859-14" == $encoding) {
//            return new ISO8859_14Decoder();
//        }
//        $canon = isset($this->CHARSET_ALIASES[$encoding]) ? $this->CHARSET_ALIASES[$encoding] : null;
//        if ($canon != null) {
//            $encoding = $canon;
//        }

        return $encoding;

//        $charset = Charset.forName(encoding);
//        return charset.newDecoder().onMalformedInput(CodingErrorAction.REPLACE);
    }

    /**
     * Determines the appropriate {@link FlagParsingStrategy} based on the FLAG definition line taken from the affix file
     *
     * @param string $flagLine Line containing the flag information
     *
     * @return FlagParsingStrategy that handles parsing flags in the way specified in the FLAG definition
     */
    static public function getFlagParsingStrategy($flagLine)
    {
        $parts = preg_split('/\s+/', $flagLine);
        if (count($parts) != 2) {
            throw new IllegalArgumentException("Illegal FLAG specification: " . $flagLine);
        }
        $flagType = $parts[1];

        if (self::NUM_FLAG_TYPE == $flagType) {
            return new NumFlagParsingStrategy();
        } elseif (self::UTF8_FLAG_TYPE == $flagType) {
            return new SimpleFlagParsingStrategy();
        } elseif (self::LONG_FLAG_TYPE == $flagType) {
            return new DoubleASCIIFlagParsingStrategy();
        }

        throw new IllegalArgumentException("Unknown flag type: {$flagType}");
    }

    const FLAG_SEPARATOR  = '';//0x1f; // flag separator after escaping
    const MORPH_SEPARATOR = '';//0x1e; // separator for boundary of entry (may be followed by morph data)

    protected function unescapeEntry($entry)
    {
        $sb = '';
        $entryLength = strlen($entry);
        $end = self::morphBoundary($entry, $entryLength);
        for ($i = 0; $i < $end; $i++) {
            $ch = $entry[$i];
            if ($ch == '\\' && $i + 1 < $entryLength) {
                $sb .= $entry[$i + 1];
                $i++;
            } elseif ($ch == '/') {
                $sb .= self::FLAG_SEPARATOR;
            } elseif ($ch == self::MORPH_SEPARATOR || $ch == self::FLAG_SEPARATOR) {
                // BINARY EXECUTABLES EMBEDDED IN ZULU DICTIONARIES!!!!!!!
            } else {
                $sb .= $ch;
            }
        }
        $sb .= self::MORPH_SEPARATOR;
        if ($end < $entryLength) {
            for ($i = $end; $i < $entryLength; $i++) {
                $c = $entry[$i];
                if ($c == self::FLAG_SEPARATOR || $c == self::MORPH_SEPARATOR) {
                    // BINARY EXECUTABLES EMBEDDED IN ZULU DICTIONARIES!!!!!!!
                } else {
                    $sb .= $c;
                }
            }
        }
        return $sb;
    }

    static protected function morphBoundary($line, $length)
    {
        $end = self::indexOfSpaceOrTab($line, 0, $length);
        if ($end === false) {
            return $length;
        }
        while ($end >= 0 && $end < $length) {
            if ($line[$end] == "\t" ||
                $end + 3 < $length &&
                preg_match('/[a-zа-яёЁ]/ui', $line[$end + 1]) &&
                preg_match('/[a-zа-яёЁ]/ui', $line[$end + 2]) &&
                $line[$end + 3] == ':'
            ) {
                break;
            }
            $end = self::indexOfSpaceOrTab($line, $end + 1, $length);
        }
        if ($end == false) {
            return $length;
        }
        return $end;
    }

    static protected function indexOfSpaceOrTab($text, $start, $length = null)
    {
        if ($length === null) {
            $length = strlen($text);
        }
        if ($length < $start) {
            return false;
        }

        $pos1 = strpos($text, "\t", $start);
        $pos2 = strpos($text, ' ', $start);
        if ($pos1 >= 0 && $pos2 >= 0) {
            return min($pos1, $pos2);
        } else {
            return max($pos1, $pos2);
        }
    }

    /**
     * Reads the dictionary file through the provided InputStreams, building up the words map
     *
     * @param resource[] $dictionaries InputStreams to read the dictionary file through
     * @param null       $decoder      CharsetDecoder used to decode the contents of the file
     * @param array      $words
     *
     * @throws IllegalArgumentException
     */
    private function readDictionaryFiles($dictionaries, $decoder, &$words)
    {
        $flagsScratch = [];

        $sb = '';

//        $unsorted = $this->createTempFile($this->tempDir, 'unsorted', 'dat');
        $unsortedLines = [];
//        $writer = fopen($unsorted, 'w+');
        try {
            /** @var resource $dictionary */
            foreach ($dictionaries as $dictionary) {
                $line = fgets($dictionary); // first line is number of entries (approximately, sometimes)
                error_log("Words in dict: {$line}");

                while (($line = fgets($dictionary)) != false) {
                    $line = rtrim($line);
                    // wild and unpredictable code comment rules
                    if (empty($line) || $line[0] == '/' || $line[0] == '#' || $line[0] == "\t") {
                        continue;
                    }
                    $line = iconv($decoder, 'utf-8', $line);

                    $line = $this->unescapeEntry($line);
                    // if we havent seen any stem exceptions, try to parse one
                    if (!$this->hasStemExceptions) {
                        $morphStart = mb_strpos($line, self::MORPH_SEPARATOR);
                        if ($morphStart >= 0 && $morphStart < strlen($line)) {
                            $this->hasStemExceptions = $this->parseStemException(substr($line, $morphStart + 1)) != null;
                        }
                    }
                    if ($this->needsInputCleaning) {
                        $flagSep = mb_strpos($line, self::FLAG_SEPARATOR);
                        if ($flagSep == false) {
                            $flagSep = mb_strpos($line, self::MORPH_SEPARATOR);
                        }
                        if ($flagSep == false) {
                            $cleansed = $this->cleanInput($line, $sb);
                            $cleansed = implode('', $cleansed);
//                            fwrite($writer, $cleansed);
                            $unsortedLines[] = $cleansed;
                        } else {
                            $text = mb_substr($line, 0, $flagSep);
                            $cleansed = $this->cleanInput($text, $sb);
                            $cleansed = implode('', $cleansed);
                            if ($cleansed != $sb) {
                                $sb = $cleansed;
                            }
                            $sb .= mb_substr($line, $flagSep);
//                            fwrite($writer, $sb);
                            $unsortedLines[] = $sb;
                        }
                    } else {
//                        fwrite($writer, $line);
                        $unsortedLines[] = $line;
                    }
                }
            }
        } catch (\Exception $e) {
        }

//        $sorted = $this->createTempFile($this->tempDir, 'sorted', 'dat');
        sort($unsortedLines, SORT_NATURAL);
//        foreach ($unsortedLines as $oneUnsortedLine) {
//            file_put_contents($sorted, $oneUnsortedLine . PHP_EOL, FILE_APPEND);
//        }
//        fclose($writer);
//        unlink($unsorted);

//        $reader = fopen($sorted, 'r+');
        try {
            // TODO: the flags themselves can be double-chars (long) or also numeric
            // either way the trick is to encode them as char... but they must be parsed differently

            $currentEntry = null;
            $currentOrds = [];


            $lineCounter = 0;
            foreach ($unsortedLines as $line) {
                $lineCounter++;
//                $line = trim(fgets($reader));
                if (empty($line)) {
                    continue;
                }
                $wordForm = [];

                $flagSep = mb_strpos($line, self::FLAG_SEPARATOR);
                if ($flagSep == false) {
                    $wordForm = self::NOFLAGS;
                    $end = mb_strpos($line, self::MORPH_SEPARATOR);
                    $entry = mb_substr($line, 0, $end);
                } else {
                    $end = mb_strpos($line, self::MORPH_SEPARATOR);
                    $flagPart = mb_substr($line, $flagSep + 1, $end);
                    if ($this->aliasCount > 0) {
                        $flagPart = $this->getAliasValue((int)($flagPart));
                    }

                    $wordForm = $this->flagParsingStrategy::parseFlags($flagPart);
                    sort($wordForm);
                    $entry = mb_substr($line, 0, $flagSep);
                }

                // we possibly have morphological data
                $stemExceptionID = 0;
                if ($this->hasStemExceptions && $end + 1 < mb_strlen($line)) {
                    $stemException = $this->parseStemException(mb_substr($line, $end + 1));
                    if ($stemException != null) {
                        $stemExceptionID = $this->stemExceptionCount + 1; // we use '0' to indicate no exception for the form
                        $this->stemExceptions[$this->stemExceptionCount++] = $stemException;
                    }
                }

                $cmp = $currentEntry == null ? 1 : $this->compareTo($entry, $currentEntry);
                if ($cmp < 0) {
                    var_dump(bin2hex($entry), bin2hex($currentEntry));
                    throw new IllegalArgumentException("out of order: {$entry} < {$currentEntry}");
                } else {
                    $this->encodeFlags($flagsScratch, $wordForm); // TODO: CHECK THAT. MB REMOVE "ENCODE"
                    $v = implode('', $flagsScratch);
                    $ord = array_search($v, $this->flagLookup, true);
                    if ($ord === false) {
                        $this->flagLookup[] = $v;
                        $ord = count($this->flagLookup) - 1;
                    }

                    // finalize current entry, and switch "current" if necessary
                    if ($cmp > 0 && $currentEntry != null) {
                        $words[$currentEntry] = $currentOrds;
                    }
                    // swap current
                    if ($cmp > 0 || $currentEntry == null) {
                        $currentEntry = $entry;
                        $currentOrds = []; //new IntsRefBuilder(); // must be this way
                    }
                    if ($this->hasStemExceptions) {
                        $currentOrds[] = $ord;
                        $currentOrds[] = $stemExceptionID;
                    } else {
                        $currentOrds[] = $ord;
                    }
                }
            }

            // finalize last entry
            $words[$currentEntry] = $currentOrds;
        } finally {
//            fclose($reader);
//            copy($sorted, '/tmp/sorted.dic');
//            unlink($sorted);
        }
    }

    private function compareTo($str1, $str2)
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        $n = min($len1, $len2);

        $k = 0;
        while ($k < $n) {
            $ch1 = $str1[$k];
            $ch2 = $str2[$k];
            if ($ch1 != $ch2) {
                return ord($ch1) - ord($ch2);
            }
            $k++;
        }
        return $len1 - $len2;
    }

    static public function decodeFlags($b)
    {
        if (strlen($b) == 0) {
            return [];
        }

        $flags = []; //new char[len];
        $upto = 0;
        $end = strlen($b);
        for ($i = 0; $i < $end; $i += 2) {
            $flags[$upto++] = ((ord($b[$i]) << 8) | (ord($b[$i + 1]) & 0xff));
        }
        return $flags;
    }

    static public function encodeFlags(&$b, $flags)
    {
        $b = [];
        for ($i = 0; $i < count($flags); $i++) {
            $flag = ord($flags[$i]);
            $b[] = chr(($flag >> 8) & 0xff);
            $b[] = chr($flag & 0xff);
        }
    }

    private function parseAlias($line)
    {
        $ruleArgs = preg_split('/\s+/', $line);
        if ($this->aliases == null) {
            //first line should be the aliases count
            $count = (int)($ruleArgs[1]);
            $this->aliases = [];
        } else {
            // an alias can map to no flags
            $aliasValue = count($ruleArgs) == 1 ? '' : $ruleArgs[1];
            $this->aliases[$this->aliasCount++] = $aliasValue;
        }
    }

    private function getAliasValue($id)
    {
        if (!isset($this->aliases[$id - 1])) {
            throw new IllegalArgumentException("Bad flag alias number: {$id}");
        }

        return $this->aliases[$id - 1];
    }

    public function getStemException($id)
    {
        return $this->stemExceptions[$id - 1];
    }

    private function parseMorphAlias($line)
    {
        if ($this->morphAliases == null) {
            //first line should be the aliases count
//        $count = (int) mb_substr($line, 3);
            $this->morphAliases = []; //new String[count];
        } else {
            $arg = mb_substr($line, 2); // leave the space
            $this->morphAliases[$this->morphAliasCount++] = $arg;
        }
    }

    private function parseStemException($morphData)
    {
        // first see if it's an alias
        if ($this->morphAliasCount > 0) {
            $alias = (int)trim($morphData);
            $morphData = $this->morphAliases[$alias - 1];
        }
        // try to parse morph entry
        $index = mb_strpos($morphData, " st:");
        if ($index === false) {
            $index = mb_strpos($morphData, "\tst:");
        }
        if ($index >= 0) {
            $endIndex = $this->indexOfSpaceOrTab($morphData, $index + 1);
            if ($endIndex === false) {
                $endIndex = mb_strlen($morphData);
            }
            return mb_substr($morphData, $index + 4, $endIndex);
        }
        return null;
    }

    static function hasFlag($flags, $flag)
    {
        return in_array($flag, $flags);
    }

    public function cleanInput($input, &$reuse)
    {
        $reuse = [];

        $input = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        $inputLen = count($input);
        for ($i = 0; $i < $inputLen; $i++) {
            $ch = $input[$i];

            if ($this->ignore != null && isset($this->ignore[$ch])) {
                continue;
            }

            if ($this->ignoreCase && $this->iconv == null) {
                // if we have no input conversion mappings, do this on-the-fly
                $ch = $this->caseFold($ch);
            }

            $reuse[] = $ch;
        }

        if ($this->iconv != null) {
            try {
                self::applyMappings($this->iconv, $reuse);
            } catch (IOException $bogus) {
                throw new \RuntimeException($bogus);
            }
            if ($this->ignoreCase) {
                for ($i = 0; $i < count($reuse); $i++) {
                    $reuse[$i] = $this->caseFold($reuse[$i]);
                }
            }
        }

        return $reuse;
    }

    /** folds single character (according to LANG if present) */
    public function caseFold($c)
    {
        if ($this->alternateCasing) {
            if ($c == 'I') {
                return 'ı';
            } else {
                if ($c == 'İ') {
                    return 'i';
                } else {
                    return mb_strtolower($c);
                }
            }
        } else {
            return mb_strtolower($c);
        }
    }

    // TODO: this could be more efficient!

    /**
     * @param array $fstStorage
     * @param                 $sb
     */
    static public function applyMappings($fstStorage, &$sb)
    {
        /** @var \calque\fst $fst */
        $fst = $fstStorage['fst'];
        $replace = $fstStorage['replace'];
        for ($i = 0; $i < ($sbCount = count($sb)); $i++) {
            $longestMatch = -1;
            $result = null;
            $longestResult = null;

            $part = '';
            for ($j = $i; $j < $sbCount; $j++) {
                $part .= $sb[$j];
//                if (($result = $fst->lookupWord($part)) === null) {
//                    break;
//                }

                list($id, $status, $err) = $fst->Get($part);
                if (!$status || !isset($replace[$id])) {
                    break;
                }

                $result = $replace[$id];
                $longestResult = $result;
            }

            if ($longestResult) {
                $longestMatch = count($longestResult);
            }

            if ($longestMatch >= 0) {
                $prefix = array_slice($sb, 0, $i);
                $suffix = array_slice($sb, $i + $longestMatch);

                $sb = array_merge($prefix, $longestResult, $suffix);
                $i += ($longestMatch - 1);
            }
        }
    }
}


/**
 * Abstraction of the process of parsing flags taken from the affix and dic files
 */
abstract class FlagParsingStrategy
{

    /**
     * Parses the given String into a single flag
     *
     * @param string rawFlag String to parse into a flag
     *
     * @return string Parsed flag
     */
    static function parseFlag($rawFlag)
    {
        $flags = static::parseFlags($rawFlag);
        if (count($flags) != 1) {
            throw new IllegalArgumentException("expected only one flag, got: {$rawFlag}");
        }
        return $flags[0];
    }

    /**
     * Parses the given String into multiple flags
     *
     * @param string rawFlags String to parse into flags
     *
     * @return string[] Parsed flags
     */
    abstract static function parseFlags($rawFlags);
}

/**
 * Simple implementation of {@link FlagParsingStrategy} that treats the chars in each String as a individual flags.
 * Can be used with both the ASCII and UTF-8 flag types.
 */
class SimpleFlagParsingStrategy extends FlagParsingStrategy
{
    public static function parseFlags($rawFlags)
    {
        return preg_split("//u", $rawFlags, -1, PREG_SPLIT_NO_EMPTY);
    }
}

/**
 * Implementation of {@link FlagParsingStrategy} that assumes each flag is encoded in its numerical form.  In the case
 * of multiple flags, each number is separated by a comma.
 */
class NumFlagParsingStrategy extends FlagParsingStrategy
{

    public static function parseFlags($rawFlags)
    {
        $rawFlagParts = explode(",", trim($rawFlags));
        $flags = []; //new char[rawFlagParts.length];
        $upto = 0;

        for ($i = 0; $i < count($rawFlagParts); $i++) {
            // note, removing the trailing X/leading I for nepali... what is the rule here?!
            $replacement = preg_replace('/[^0-9]/', '', $rawFlagParts[$i]);
            // note, ignoring empty flags (this happens in danish, for example)
            if (empty($replacement)) {
                continue;
            }
            $flags[$upto++] = (int)($replacement);
        }

        if ($upto < count($flags)) {
            $flags = array_slice($flags, 0, $upto);
        }
        return $flags;
    }
}

/**
 * Implementation of {@link FlagParsingStrategy} that assumes each flag is encoded as two ASCII characters whose codes
 * must be combined into a single character.
 */
class DoubleASCIIFlagParsingStrategy extends FlagParsingStrategy
{

    public static function parseFlags($rawFlags)
    {
        if (mb_strlen($rawFlags) == 0) {
            return []; //new char[0];
        }

        $builder = '';
        if (mb_strlen($rawFlags) % 2 == 1) {
            throw new IllegalArgumentException("Invalid flags (should be even number of characters): {$rawFlags}");
        }
        for ($i = 0; $i < mb_strlen($rawFlags); $i += 2) {
            $f1 = $rawFlags[$i];
            $f2 = $rawFlags[$i + 1];
            if (ord($f1) >= 256 || ord($f2) >= 256) {
                throw new IllegalArgumentException("Invalid flags (LONG flags must be double ASCII): {$rawFlags}");
            }
            $combined = chr((ord($f1) << 8 | ord($f2)));
            $builder .= $combined;
        }

        $flags = preg_split("//u", $builder, -1, PREG_SPLIT_NO_EMPTY); //new char[builder . length()];

        return $flags;
    }
}

class ParseException extends \Exception
{
}

class UnsupportedOperationException extends \Exception
{
}

class IllegalStateException extends \Exception
{
}

class IndexOutOfBoundException extends \RuntimeException
{
}

class BytesRef
{
    public $offset = 0;

    public $bytes = [];

    public $length = 0;

    public function grow($len)
    {
    }

    public function clear()
    {
        $this->offset = 0;
    }

    public function append($param)
    {
        $this->bytes[$this->length++] = $param;
    }

    public function compareTo(BytesRef $other): int
    {
        // TODO: Once we are on Java 9 replace this by java.util.Arrays#compareUnsigned()
        // which is implemented by a Hotspot intrinsic! Also consider building a
        // Multi-Release-JAR!
        $aBytes = $this->bytes;
        $aUpto = $this->offset;
        $bBytes = $other->bytes;
        $bUpto = $other->offset;

        $aStop = $aUpto + min($this->length, $other->length);
        while ($aUpto < $aStop) {
            $aByte = $aBytes[$aUpto++] & 0xff;
            $bByte = $bBytes[$bUpto++] & 0xff;

            $diff = $aByte - $bByte;
            if ($diff != 0) {
                return $diff;
            }
        }

// One is a prefix of the other, or, they are equal:
        return $this->length - $other->length;
    }
}

class BytesRefBuilder
{

    /** @var BytesRef */
    protected $bytesRef;

    public function __construct()
    {
        $this->bytesRef = new BytesRef();
    }

    /**
     * Append the provided bytes to this builder.
     *
     * @param string[] $b
     * @param null     $off
     * @param null     $len
     *
     * @return void
     */
    public function append($b, $off = null, $len = null)
    {

    }

    /**
     * Append the provided bytes to this builder.
     *
     * @param BytesRef $ref
     *
     * @return void
     */
    public function appendBytesRef(BytesRef $ref)
    {
    }

    /**
     * Append the provided bytes to this builder.
     *
     * @param BytesRefBuilder $builder
     *
     * @return void
     */
    public function appendBytesRefBuilder(BytesRefBuilder $builder)
    {
    }

    /**
     * Return the byte at the given offset.
     *
     * @param int $offset
     *
     * @return string
     */
    public function byteAt($offset)
    {
    }

    /**
     * Return a reference to the bytes of this builder.
     * @return string[]
     */
    public function bytes()
    {
    }

    /**
     * Reset this builder to the empty state.
     * @return void
     */
    public function clear()
    {
    }

    /**
     * Replace the content of this builder with the provided bytes.
     *
     * @param string[] $b
     * @param int      $off
     * @param int      $len
     *
     * @return void
     */
    public function copyBytes($b, $off, $len)
    {
    }

    /**
     * Replace the content of this builder with the provided bytes.
     *
     * @param BytesRef $ref
     *
     * @return void
     */
    public function copyBytesBytesRef(BytesRef $ref)
    {
    }

    /**
     * Replace the content of this builder with the provided bytes.
     * @return void
     */
    public function copyBytesBytesRefBuilder(BytesRefBuilder $builder)
    {
    }

    /**
     * Replace the content of this buffer with UTF-8 encoded bytes that would represent the provided text.
     *
     * @param string[] $text
     * @param int      $off
     * @param int      $len
     *
     * @return void
     */
    public function copyChars($text, $off, $len)
    {
    }

    /**
     * Replace the content of this buffer with UTF-8 encoded bytes that would represent the provided text.
     *
     * @param string   $text
     * @param int|null $off
     * @param int|null $len
     *
     * @return void
     */
    public function copyCharsCharSequence(string $text, $off = null, $len = null)
    {
    }

    /**
     * Return a BytesRef that points to the internal content of this builder.
     * @return BytesRef
     */
    public function get()
    {
    }

    /**
     * Ensure that this builder can hold at least capacity bytes without resizing.
     * @return void
     */
    public function grow($capacity)
    {
    }

    /**
     * Return the number of bytes in this buffer.
     * @return int
     */
    public function length()
    {
    }

    /**
     * Set a byte.
     * @return void
     */
    public function setByteAt($offset, $b)
    {
    }

    /**
     * Set the length.
     * @return void
     */
    public function setLength($length)
    {
    }

    /**
     * Build a new BytesRef that has the same content as this buffer.
     * @return BytesRef
     */
    public function toBytesRef()
    {
    }


    public function equals(BytesRefBuilder $obj)
    {
        throw new \Exception('Not implemented');
    }

    public function hashCode()
    {
        return crc32(mt_rand(0, PHP_INT_MAX));
    }
}