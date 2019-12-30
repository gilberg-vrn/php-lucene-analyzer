<?php

namespace ftIndex\analyses\hunspell;

use ftIndex\fst\Util;
use ftIndex\store\ByteArrayDataInput;
use ftIndex\util\BitUtil;

class Stemmer
{
    /**
     * @var dictionary
     */
    private $dictionary; //Dictionary
    private $scratch = []; //BytesRef
    private $segment = ''; //StringBuilder
    /** @var ByteArrayDataInput */
    private $affixReader;

    // used for normalization
    private $scratchSegment = '';
    private $scratchBuffer = []; //new char[32];

    // it's '1' if we have no stem exceptions, otherwise every other form
    // is really an ID pointing to the exception table
    private $formStep; //int

    // temporary buffers for case variants
    private $lowerBuffer = []; //new char[8];
    private $titleBuffer = []; //new char[8];

    const EXACT_CASE = 0;
    const TITLE_CASE = 1;
    const UPPER_CASE = 2;

    // ================================================= Helper Methods ================================================

    // some state for traversing FSTs
    protected $prefixReaders = []; // new FST.BytesReader[3];

    protected $prefixArcs = []; // new FST.Arc[3];

    protected $suffixReaders = []; // new FST.BytesReader[3];

    protected $suffixArcs = []; // new FST.Arc[3];

    /**
     * Constructs a new Stemmer which will use the provided Dictionary to create its stems.
     *
     * @param dictionary Dictionary that will be used to create the stems
     */
    public function __construct(dictionary $dictionary)
    {
        $this->dictionary = $dictionary;
        $this->affixReader = new ByteArrayDataInput($this->dictionary->affixData);
        for ($level = 0; $level < 3; $level++) {
            if ($this->dictionary->prefixes != null) {
                $this->prefixArcs[$level] = []; //new FST.Arc<>();
                $this->prefixReaders[$level] = $this->dictionary->prefixes;//dictionary.prefixes.getBytesReader();
            }
            if ($this->dictionary->suffixes != null) {
                $this->suffixArcs[$level] = []; //new FST.Arc<>();
                $this->suffixReaders[$level] = $this->dictionary->suffixes; //dictionary.suffixes.getBytesReader();
            }
        }
        $this->formStep = $this->dictionary->hasStemExceptions ? 2 : 1;
    }

    /**
     * Find the stem(s) of the provided word.
     *
     * @param string word Word to find the stems for
     *
     * @return array List of stems for the word
     */
    public function stemWord($word)
    {
        return $this->stemWord2($word, mb_strlen($word));
    }

    /**
     * Find the stem(s) of the provided word
     *
     * @param string $word Word to find the stems for
     * @param int    $length
     *
     * @return array List of stems for the word
     */
    public function stemWord2($word, $length)
    {
        if ($this->dictionary->needsInputCleaning) {
            $this->scratchSegment = $word;
            $this->dictionary->cleanInput($this->scratchSegment, $segment);
            $length = count($segment);
            $this->scratchBuffer = $segment;
            $word = implode('', $this->scratchBuffer);
        }

        $caseType = $this->caseOf($word, $length);
        if ($caseType == self::UPPER_CASE) {
            // upper: union exact, title, lower
            $this->caseFoldTitle($word, $length);
            $this->caseFoldLower($this->titleBuffer, $length);
            $list = $this->doStem($word, $length, false);

            $list = array_merge($list, $this->doStem($this->titleBuffer, $length, true));
            $list = array_merge($list, $this->doStem($this->lowerBuffer, $length, true));
            return $list;
        } elseif ($caseType == self::TITLE_CASE) {
            // title: union exact, lower
            $this->caseFoldLower($word, $length);
            $list = $this->doStem($word, $length, false);
            $list = array_merge($list, $this->doStem($this->lowerBuffer, $length, true));
            return $list;
        } else {
            // exact match only
            return $this->doStem($word, $length, false);
        }
    }

    protected function isUpperCase($char)
    {
        return (bool)preg_match('/[A-ZА-ЯЁ]/u', $char);
    }

    /** returns EXACT_CASE,TITLE_CASE, or UPPER_CASE type for the word */
    private function caseOf($word, $length)
    {
        if ($this->dictionary->ignoreCase || $length == 0 || !$this->isUpperCase($word[0])) {
            return self::EXACT_CASE;
        }

        // determine if we are title or lowercase (or something funky, in which it's exact)
        $seenUpper = false;
        $seenLower = false;
        for ($i = 1; $i < $length; $i++) {
            $v = $this->isUpperCase($word[$i]);
            $seenUpper |= $v;
            $seenLower |= !$v;
        }

        if (!$seenLower) {
            return self::UPPER_CASE;
        } else {
            if (!$seenUpper) {
                return self::TITLE_CASE;
            } else {
                return self::EXACT_CASE;
            }
        }
    }

    /** folds titlecase variant of word to titleBuffer
     *
     * @param $word
     * @param $length
     */
    private function caseFoldTitle($word, $length)
    {
        $this->titleBuffer = $word;
        for ($i = 1; $i < $length; $i++) {
            $this->titleBuffer[$i] = $this->dictionary->caseFold($this->titleBuffer[$i]);
        }
    }

    /** folds lowercase variant of word (title cased) to lowerBuffer */
    private function caseFoldLower($word, $length)
    {
        $this->lowerBuffer = $word;
        $this->lowerBuffer[0] = $this->dictionary->caseFold($this->lowerBuffer[0]);
    }

    private function doStem($word, $length, $caseVariant)
    {
        $stems = []; //new ArrayList<>()
        $forms = $this->dictionary->lookupWord($word, 0, $length);
        if ($forms != null) {
            for ($i = 0; $i < count($forms); $i += $this->formStep) {
                $checkKeepCase = $caseVariant && $this->dictionary->keepcase != -1;
                $checkNeedAffix = $this->dictionary->needaffix != -1;
                $checkOnlyInCompound = $this->dictionary->onlyincompound != -1;
                if ($checkKeepCase || $checkNeedAffix || $checkOnlyInCompound) {
                    $scratch = $this->dictionary->flagLookup[$forms[$i]];
                    $wordFlags = $this->dictionary->decodeFlags($scratch);
                    // we are looking for a case variant, but this word does not allow it
                    if ($checkKeepCase && $this->dictionary->hasFlag($wordFlags, $this->dictionary->keepcase)) {
                        continue;
                    }
                    // we can't add this form, it's a pseudostem requiring an affix
                    if ($checkNeedAffix && $this->dictionary->hasFlag($wordFlags, $this->dictionary->needaffix)) {
                        continue;
                    }
                    // we can't add this form, it only belongs inside a compound word
                    if ($checkOnlyInCompound && $this->dictionary->hasFlag($wordFlags, $this->dictionary->onlyincompound)) {
                        continue;
                    }
                }
                $stems[] = $this->newStem($word, $length, $forms, $i);
            }
        }
        try {
            $stems = array_merge($stems, ($this->stem($word, $length, -1, -1, -1, 0, true, true, false, false, $caseVariant)));
        } catch (IOException $bogus) {
            throw new \RuntimeException('', 0, $bogus);
        }
        return $stems;
    }

    /**
     * Find the unique stem(s) of the provided word
     *
     * @param string word Word to find the stems for
     *
     * @return array List of stems for the word
     */
    public function uniqueStems($word, $length)
    {
        $stems = $this->stemWord2($word, $length);
        if (count($stems) < 2) {
            return $stems;
        }
        $terms = [];
        $deDup = [];
        foreach ($stems as $s) {
            $c = $this->dictionary->ignoreCase ? mb_strtolower($s) : $s;
            if (!isset($terms[$c])) {
                $deDup[] = $s;
                $terms[$c] = true;
            }
        }
        return $deDup;
    }

    private function newStem($buffer, $length, $forms, $formID)
    {
//    $exception;
        if (is_array($buffer)) {
            $buffer = implode('', $buffer);
        }

        if ($this->dictionary->hasStemExceptions) {
            $exceptionID = $forms[$formID + 1];
            if ($exceptionID > 0) {
                $exception = $this->dictionary->getStemException($exceptionID);
            } else {
                $exception = null;
            }
        } else {
            $exception = null;
        }

        if ($this->dictionary->needsOutputCleaning) {
            $this->scratchSegment = '';
            if ($exception != null) {
                $this->scratchSegment .= $exception;
            } else {
                $this->scratchSegment .= $buffer;
            }
            try {
                dictionary::applyMappings($this->dictionary->oconv, $this->scratchSegment);
            } catch (IOException $bogus) {
                throw new \RuntimeException('', 0, $bogus);
            }
            $cleaned = $this->scratchSegment;

            return $cleaned;
        } else {
            if ($exception != null) {
                return $exception;
            } else {
                return $buffer;
            }
        }
    }


    /**
     * Generates a list of stems for the provided word
     *
     * @param array|string $word              Word to generate the stems for
     * @param string       $previous          previous affix that was removed (so we dont remove same one twice)
     * @param string       $prevFlag          Flag from a previous stemming step that need to be cross-checked with any affixes in this recursive step
     * @param string       $prefixFlag        flag of the most inner removed prefix, so that when removing a suffix, it's also checked against the word
     * @param int          $recursionDepth    current recursiondepth
     * @param bool         $doPrefix          true if we should remove prefixes
     * @param bool         $doSuffix          true if we should remove suffixes
     * @param bool         $previousWasPrefix true if the previous removal was a prefix:
     *                                        if we are removing a suffix, and it has no continuation requirements, it's ok.
     *                                        but two prefixes (COMPLEXPREFIXES) or two suffixes must have continuation requirements to recurse.
     * @param bool         $circumfix         true if the previous prefix removal was signed as a circumfix
     *                                        this means inner most suffix must also contain circumfix flag.
     * @param bool         $caseVariant       true if we are searching for a case variant. if the word has KEEPCASE flag it cannot succeed.
     *
     * @return array List of stems, or empty list if no stems are found
     */
    public function stem($word, $length, $previous, $prevFlag, $prefixFlag, $recursionDepth, $doPrefix, $doSuffix, $previousWasPrefix, $circumfix, $caseVariant)
    {
        if (!is_array($word)) {
            $word = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        }
        // TODO: allow this stuff to be reused by tokenfilter
        $stems = []; //new ArrayList<>();

        if ($doPrefix && $this->dictionary->prefixes != null) {
            $lookupPrefix = '';
            $prefixes = [];

            $matchPrefixes = [];
            foreach ($this->dictionary->prefixes as $fstPrefix => $fstPrefixId) {
                if (!isset($this->dictionary->prefixesCache[$fstPrefix])) {
                    $this->dictionary->prefixesCache[$fstPrefix] = preg_split('//u', $fstPrefix, -1, PREG_SPLIT_NO_EMPTY);
                } else {
                    if ($this->dictionary->prefixesCache[$fstPrefix][0] === $word[0]) {
                        $matchPrefixes[] = $fstPrefix;
                    }
                }
            }

            $limit = $this->dictionary->fullStrip ? $length : $length - 1;
            for ($i = 0; $i < $limit; $i++) {
                if ($i > 0) {
                    $lookupPrefix .= $word[$i - 1];
                    $foundPrefix = false;
                    foreach ($matchPrefixes as $fstPrefix) {
//                    foreach ($this->dictionary->prefixes as $fstPrefix => $fstPrefixId) {
                        if ($fstPrefix == $lookupPrefix) {
                            $prefixes = array_merge($prefixes, $this->dictionary->prefixes[$fstPrefix]);
                            array_unique($prefixes);
                            $foundPrefix = true;
                        } elseif (preg_match("/^{$lookupPrefix}/", $fstPrefix) > 0) {
                            continue 2;
                        }
                    }

                    if (!$foundPrefix) {
                        break;
                    }
                } else {
                    continue;
                }

                $prefixesCount = count($prefixes);
                for ($j = 0; $j < $prefixesCount; $j++) {
                    $prefix = $prefixes[$j];
                    if ($prefix == $previous) {
                        continue;
                    }
                    $this->affixReader->setPosition(8 * $prefix);
                    $flag = chr($this->affixReader->readShort() & 0xffff);
                    $stripOrd = $this->affixReader->readShort() & 0xffff;
                    $condition = $this->affixReader->readShort() & 0xffff;
                    $crossProduct = ($condition & 1) == 1;
                    $condition = $condition >> 1;
                    $append = $this->affixReader->readShort() & 0xffff;

                    if ($recursionDepth == 0) {
                        if ($this->dictionary->onlyincompound == -1) {
                            $compatible = true;
                        } else {
                            // check if affix is allowed in a non-compound word
                            $scratch = $this->dictionary->flagLookup[$append];
                            $appendFlags = dictionary::decodeFlags($scratch);
                            $compatible = !dictionary::hasFlag($appendFlags, chr($this->dictionary->onlyincompound));
                        }
                    } elseif ($crossProduct) {
                        // cross check incoming continuation class (flag of previous affix) against list.
                        $scratch = $this->dictionary->flagLookup[$append];
                        $appendFlags = dictionary::decodeFlags($scratch);
//            assert prevFlag >= 0;
                        if ($prevFlag < 0) {
                            throw new \AssertionError('Not: prevFlag >= 0');
                        }

                        $allowed = $this->dictionary->onlyincompound == -1 ||
                            !dictionary::hasFlag($appendFlags, chr($this->dictionary->onlyincompound));
                        $compatible = $allowed && $this->hasCrossCheckedFlag(chr($prevFlag), $appendFlags, false);
                    } else {
                        $compatible = false;
                    }

                    if ($compatible) {
                        $deAffixedStart = $i;
                        $deAffixedLength = $length - $deAffixedStart;

                        $stripStart = $this->dictionary->stripOffsets[$stripOrd];
                        $stripEnd = $this->dictionary->stripOffsets[$stripOrd + 1];
                        $stripLength = $stripEnd - $stripStart;

                        if (!$this->checkCondition($condition, $this->dictionary->stripData, $stripStart, $stripLength, $word, $deAffixedStart, $deAffixedLength)) {
                            continue;
                        }

                        $strippedWord = []; //new char[stripLength + deAffixedLength];
                        Util::arraycopy($this->dictionary->stripData, $stripStart, $strippedWord, 0, $stripLength);
//                        $strippedWord = array_slice($this->dictionary->stripData, $stripStart, $stripLength);
                        Util::arraycopy($word, $deAffixedStart, $strippedWord, $stripLength, $deAffixedLength);
//                        $strippedWord = array_merge($strippedWord, array_slice($word, $deAffixedStart, $deAffixedLength));

                        $stemList = $this->applyAffix($strippedWord, count($strippedWord), $prefix, -1, $recursionDepth, true, $circumfix, $caseVariant);

                        $stems = array_merge($stems, $stemList);
                    }
                }
            }
        }


        if ($doSuffix && $this->dictionary->suffixes != null) {
            $lookupSuffix = '';
            $limit = $this->dictionary->fullStrip ? 0 : 1;

            $matchSuffixes = [];
            foreach ($this->dictionary->suffixes as $fstSuffix => $fstSuffixId) {
                if (!isset($fstSuffix[0])) {
                    continue;
                }

                if (!isset($this->dictionary->suffixesCache[$fstSuffix])) {
                    $this->dictionary->suffixesCache[$fstSuffix] = preg_split('//u', $fstSuffix, -1, PREG_SPLIT_NO_EMPTY);
                } else {
                    if ($this->dictionary->suffixesCache[$fstSuffix][0] === $word[$length - 1]) {
                        $matchSuffixes[] = $fstSuffix;
                    }
                }
            }

            for ($i = $length; $i >= $limit; $i--) {
                $suffixes = [];
                if ($i < $length) {
                    $lookupSuffix .= $word[$i];
                    $foundSuffix = false;
                    $foundNextSuffix = false;
                    foreach ($matchSuffixes as $fstSuffix) {
//                    foreach ($this->dictionary->suffixes as $fstSuffix => $fstSuffixId) {
                        if ($fstSuffix === $lookupSuffix) {
                            $foundSuffix = true;
                            $suffixes = $this->dictionary->suffixes[$fstSuffix];
                            break;

                        } elseif (strpos($fstSuffix, $lookupSuffix) === 0) {
                            $suffixes = $this->dictionary->suffixes[$fstSuffix];
                            $foundNextSuffix = true;
                        }
//                        } elseif (preg_match("/^{$lookupSuffix}/", $fstSuffix) > 0) {
//                            $foundNextSuffix = true;
//                            $suffixes = $fstSuffixId;
//                        }
                    }

                    if (!$foundSuffix) {
                        if ($foundNextSuffix) {
                            continue;
                        }
                        break;
                    }
                } else {
                    if (isset($this->dictionary->suffixes[$lookupSuffix])) {
                        $suffixes = $this->dictionary->suffixes[$lookupSuffix];
                    }
                }

                $suffixesCount = count($suffixes);
                for ($j = 0; $j < $suffixesCount; $j++) {
                    $suffix = $suffixes[$j];
                    if ($suffix == $previous) {
                        continue;
                    }
                    $this->affixReader->setPosition(8 * $suffix);
                    $flag = chr($this->affixReader->readShort() & 0xffff);
                    $stripOrd = $this->affixReader->readShort() & 0xffff;
                    $condition = $this->affixReader->readShort() & 0xffff;
                    $crossProduct = ($condition & 1) == 1;
                    $condition = $condition >> 1;
                    $append = $this->affixReader->readShort() & 0xffff;

                    if ($recursionDepth == 0) {
                        if ($this->dictionary->onlyincompound == -1) {
                            $compatible = true;
                        } else {
                            // check if affix is allowed in a non-compound word
                            $scratch = $this->dictionary->flagLookup[$append];
                            $appendFlags = dictionary::decodeFlags($scratch);
                            $compatible = !dictionary::hasFlag($appendFlags, chr($this->dictionary->onlyincompound));
                        }
                    } elseif ($crossProduct) {
                        // cross check incoming continuation class (flag of previous affix) against list.
                        $scratch = $this->dictionary->flagLookup[$append];
                        $appendFlags = dictionary::decodeFlags($scratch);
//            assert prevFlag >= 0;
                        if ($prevFlag < 0) {
                            throw new \AssertionError('Not: prevFlag >= 0');
                        }

                        $allowed = $this->dictionary->onlyincompound == -1 ||
                            !dictionary::hasFlag($appendFlags, chr($this->dictionary->onlyincompound));
                        $compatible = $allowed && $this->hasCrossCheckedFlag(chr($prevFlag), $appendFlags, $previousWasPrefix);
                    } else {
                        $compatible = false;
                    }

                    if ($compatible) {
                        $appendLength = $length - $i;
                        $deAffixedLength = $length - $appendLength;

                        $stripStart = $this->dictionary->stripOffsets[$stripOrd];
                        $stripEnd = $this->dictionary->stripOffsets[$stripOrd + 1];
                        $stripLength = $stripEnd - $stripStart;

                        if (!$this->checkCondition($condition, $word, 0, $deAffixedLength, $this->dictionary->stripData, $stripStart, $stripLength)) {
                            continue;
                        }

                        $strippedWord = []; //new char[stripLength + deAffixedLength];
                        Util::arraycopy($word, 0, $strippedWord, 0, $deAffixedLength);
                        Util::arraycopy($this->dictionary->stripData, $stripStart, $strippedWord, $deAffixedLength, $stripLength);

                        $stemList = $this->applyAffix($strippedWord, count($strippedWord), $suffix, $prefixFlag, $recursionDepth, false, $circumfix, $caseVariant);

                        $stems = array_merge($stems, $stemList);
                    }
                }
            }
        }

        return $stems;
    }

    /** checks condition of the concatenation of two strings
     *
     * @param $condition
     * @param $c1
     * @param $c1off
     * @param $c1len
     * @param $c2
     * @param $c2off
     * @param $c2len
     *
     * @return bool
     */
    // note: this is pretty stupid, we really should subtract strip from the condition up front and just check the stem
    // but this is a little bit more complicated.
    private function checkCondition($condition, $c1, $c1off, $c1len, $c2, $c2off, $c2len)
    {
        if ($condition != 0) {
            if (!isset($this->dictionary->patterns[$condition])) {
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            }
            $pattern = $this->dictionary->patterns[$condition];
//            $state = $pattern->getInitialState();

            $c1string = implode('', array_slice($c1, $c1off, $c1len));
            $c2string = implode('', array_slice($c2, $c2off, $c2len));
            $stringForCheck = $c1string . $c2string;

            return (bool)preg_match($pattern, $stringForCheck);


//            if (!isset($this->dictionary->patterns[$condition])) {
//                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
//            }
//            $pattern = $this->dictionary->patterns[$condition];
//            $state = $pattern->getInitialState();
//
//            for ($i = $c1off; $i < $c1off + $c1len; $i++) {
//                $state = $pattern->step($state, \IntlChar::ord($c1[$i]));
//                if ($state == -1) {
//                    var_dump('DAMN1');
//                    return false;
//                }
//            }
//            for ($i = $c2off; $i < $c2off + $c2len; $i++) {
//                $state = $pattern->step($state, \IntlChar::ord($c2[$i]));
//                if ($state == -1) {
//                    var_dump('DAMN2');
//                    return false;
//                }
//            }
//            return $pattern->isAccept($state);

        }

        return true;
    }

    /**
     * Applies the affix rule to the given word, producing a list of stems if any are found
     *
     * @param string|array $strippedWord   Word the affix has been removed and the strip added
     * @param int          $length         valid length of stripped word
     * @param int          $affix          HunspellAffix representing the affix rule itself
     * @param int          $prefixFlag     when we already stripped a prefix, we cant simply recurse and check the suffix, unless both are compatible
     *                                     so we must check dictionary form against both to add it as a stem!
     * @param int          $recursionDepth current recursion depth
     * @param bool         $prefix         true if we are removing a prefix (false if it's a suffix)
     *
     * @return array List of stems for the word, or an empty list if none are found
     */
    protected function applyAffix($strippedWord, $length, $affix, $prefixFlag, $recursionDepth, $prefix, $circumfix, $caseVariant)
    {
        // TODO: just pass this in from before, no need to decode it twice
        $this->affixReader->setPosition(8 * $affix);
        $flag = $this->affixReader->readShort() & 0xffff;
        $this->affixReader->skipBytes(2); // strip
        $condition = $this->affixReader->readShort() & 0xffff;
        $crossProduct = ($condition & 1) == 1;
        BitUtil::uRShift($condition, 1);
        $append = $this->affixReader->readShort() & 0xffff;

        $stems = []; //new ArrayList<>();

        $forms = $this->dictionary->lookupWord($strippedWord, 0, $length);
        if ($forms != null) {
            for ($i = 0; $i < count($forms); $i += $this->formStep) {
                $scratch = $this->dictionary->flagLookup[$forms[$i]];
                $wordFlags = dictionary::decodeFlags($scratch);
                if (dictionary::hasFlag($wordFlags, $flag)) {
                    // confusing: in this one exception, we already chained the first prefix against the second,
                    // so it doesnt need to be checked against the word
                    $chainedPrefix = $this->dictionary->complexPrefixes && $recursionDepth == 1 && $prefix;
                    if ($chainedPrefix == false && $prefixFlag >= 0 && !dictionary::hasFlag($wordFlags, chr($prefixFlag))) {
                        // see if we can chain prefix thru the suffix continuation class (only if it has any!)
                        $scratch = $this->dictionary->flagLookup[$append];
                        $appendFlags = dictionary::decodeFlags($scratch);
                        if (!$this->hasCrossCheckedFlag(chr($prefixFlag), $appendFlags, false)) {
                            continue;
                        }
                    }

                    // if circumfix was previously set by a prefix, we must check this suffix,
                    // to ensure it has it, and vice versa
                    if ($this->dictionary->circumfix != -1) {
                        $scratch = $this->dictionary->flagLookup[$append];
                        $appendFlags = dictionary::decodeFlags($scratch);
                        $suffixCircumfix = dictionary::hasFlag($appendFlags, chr($this->dictionary->circumfix));
                        if ($circumfix != $suffixCircumfix) {
                            continue;
                        }
                    }

                    // we are looking for a case variant, but this word does not allow it
                    if ($caseVariant && $this->dictionary->keepcase != -1 && dictionary::hasFlag($wordFlags, chr($this->dictionary->keepcase))) {
                        continue;
                    }
                    // we aren't decompounding (yet)
                    if ($this->dictionary->onlyincompound != -1 && dictionary::hasFlag($wordFlags, chr($this->dictionary->onlyincompound))) {
                        continue;
                    }
                    $stems[] = $this->newStem($strippedWord, $length, $forms, $i);
                }
            }
        }

        // if a circumfix flag is defined in the dictionary, and we are a prefix, we need to check if we have that flag
        if ($this->dictionary->circumfix != -1 && !$circumfix && $prefix) {
            $scratch = $this->dictionary->flagLookup[$append];
            $appendFlags = dictionary::decodeFlags($scratch);
            $circumfix = dictionary::hasFlag($appendFlags, chr($this->dictionary->circumfix));
        }

        if ($crossProduct) {
            if ($recursionDepth == 0) {
                if ($prefix) {
                    // we took away the first prefix.
                    // COMPLEXPREFIXES = true:  combine with a second prefix and another suffix
                    // COMPLEXPREFIXES = false: combine with a suffix
                    $stems = array_merge($stems, $this->stem($strippedWord, $length, $affix, $flag, $flag, ++$recursionDepth, $this->dictionary->complexPrefixes && $this->dictionary->twoStageAffix, true, true, $circumfix, $caseVariant));
                } elseif ($this->dictionary->complexPrefixes == false && $this->dictionary->twoStageAffix) {
                    // we took away a suffix.
                    // COMPLEXPREFIXES = true: we don't recurse! only one suffix allowed
                    // COMPLEXPREFIXES = false: combine with another suffix
                    $stems = array_merge($stems, $this->stem($strippedWord, $length, $affix, $flag, $prefixFlag, ++$recursionDepth, false, true, false, $circumfix, $caseVariant));
                }
            } elseif ($recursionDepth == 1) {
                if ($prefix && $this->dictionary->complexPrefixes) {
                    // we took away the second prefix: go look for another suffix
                    $stems = array_merge($stems, $this->stem($strippedWord, $length, $affix, $flag, $flag, ++$recursionDepth, false, true, true, $circumfix, $caseVariant));
                } elseif ($prefix == false && $this->dictionary->complexPrefixes == false && $this->dictionary->twoStageAffix) {
                    // we took away a prefix, then a suffix: go look for another suffix
                    $stems = array_merge($stems, $this->stem($strippedWord, $length, $affix, $flag, $prefixFlag, ++$recursionDepth, false, true, false, $circumfix, $caseVariant));
                }
            }
        }

        return $stems;
    }

    /**
     * Checks if the given flag cross checks with the given array of flags
     *
     * @param string flag Flag to cross check with the array of flags
     * @param array flags Array of flags to cross check against.  Can be {@code null}
     *
     * @return {@code true} if the flag is found in the array or the array is {@code null}, {@code false} otherwise
     */
    private function hasCrossCheckedFlag($flag, $flags, $matchEmpty)
    {
        return (count($flags) == 0 && $matchEmpty) || in_array($flag, $flags) >= 0;
    }
}