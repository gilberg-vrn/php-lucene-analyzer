<?php

namespace ftIndex\analyses;

use ftIndex\io\Reader;
use IntlChar;

/**
 * Class StandardTokenizer
 *
 * @package ftIndex\analyses
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    8/29/19 5:17 PM
 */
final class StandardTokenizer extends TokenStream
{
    /**
     * The minimum value of a high surrogate or leading surrogate unit in UTF-16
     * encoding, {@code '\u{D800}'}.
     *
     * @since 1.5
     */
    const MIN_HIGH_SURROGATE = "\u{d800}";
    /**
     * The maximum value of a high surrogate or leading surrogate unit in UTF-16
     * encoding, {@code '\u{DBFF}'}.
     *
     * @since 1.5
     */
    const MAX_HIGH_SURROGATE = "\u{dbff}";
    /**
     * The minimum value of a low surrogate or trailing surrogate unit in UTF-16
     * encoding, {@code '\uDC00'}.
     *
     * @since 1.5
     */
    const MIN_LOW_SURROGATE = "\u{dc00}";
    /**
     * The maximum value of a low surrogate or trailing surrogate unit in UTF-16
     * encoding, {@code '\uDFFF'}.
     *
     * @since 1.5
     */
    const MAX_LOW_SURROGATE = "\u{dfff}";
    /**
     * The minimum value of a surrogate unit in UTF-16 encoding, {@code '\uD800'}.
     *
     * @since 1.5
     */
    const MIN_SURROGATE = "\u{d800}";
    /**
     * The maximum value of a surrogate unit in UTF-16 encoding, {@code '\uDFFF'}.
     *
     * @since 1.5
     */
    const MAX_SURROGATE = "\u{dfff}";

    // TODO: how can we remove these old types?!
    /** Alpha/numeric token type */
    const ALPHANUM = 0;
    /** @deprecated (3.1) */
    const APOSTROPHE = 1;
    /** @deprecated (3.1) */
    const ACRONYM = 2;
    /** @deprecated (3.1) */
    const COMPANY = 3;
    /** Email token type */
    const EMAIL = 4;
    /** @deprecated (3.1) */
    const HOST = 5;
    /** Numeric token type */
    const NUM = 6;
    /** @deprecated (3.1) */
    const CJ = 7;

    /** @deprecated (3.1) */
    const ACRONYM_DEP = 8;

    /** Southeast Asian token type */
    const SOUTHEAST_ASIAN = 9;
    /** Idiographic token type */
    const IDEOGRAPHIC = 10;
    /** Hiragana token type */
    const HIRAGANA = 11;
    /** Katakana token type */
    const KATAKANA = 12;

    /** Hangul token type */
    const HANGUL = 13;

    /** String token types that correspond to token type int constants */
    public static $TOKEN_TYPES = [
        "<ALPHANUM>",
        "<APOSTROPHE>",
        "<ACRONYM>",
        "<COMPANY>",
        "<EMAIL>",
        "<HOST>",
        "<NUM>",
        "<CJ>",
        "<ACRONYM_DEP>",
        "<SOUTHEAST_ASIAN>",
        "<IDEOGRAPHIC>",
        "<HIRAGANA>",
        "<KATAKANA>",
        "<HANGUL>"
    ];

    /** This character denotes the end of file */
    public static $YYEOF = -1;

    /** @var int */
    protected $skippedPositions = 0;

    /** initial size of the lookahead buffer */
    private $ZZ_BUFFERSIZE = 255;

    /** lexical states */
    public static $YYINITIAL = 0;

    /**
     * ZZ_LEXSTATE[l] is the state in the DFA for the lexical state l
     * ZZ_LEXSTATE[l+1] is the state in the DFA for the lexical state l
     *                  at the beginning of a line
     * l is of the form l = 2*k, k a non negative integer
     */
    private static $ZZ_LEXSTATE = [
        0, 0
    ];

    /**
     * Translates characters to character classes
     */
    private static $ZZ_CMAP_PACKED =
        "\u{22}\u{0}\u{1}\u{d}\u{4}\u{0}\u{1}\u{c}\u{4}\u{0}\u{1}\u{7}\u{1}\u{0}\u{1}\u{8}" .
        "\u{1}\u{0}\u{a}\u{4}\u{1}\u{6}\u{1}\u{7}\u{5}\u{0}\u{1a}\u{1}\u{4}\u{0}\u{1}\u{9}" .
        "\u{1}\u{0}\u{1a}\u{1}\u{2f}\u{0}\u{1}\u{1}\u{2}\u{0}\u{1}\u{3}\u{7}\u{0}\u{1}\u{1}" .
        "\u{1}\u{0}\u{1}\u{6}\u{2}\u{0}\u{1}\u{1}\u{5}\u{0}\u{17}\u{1}\u{1}\u{0}\u{1f}\u{1}" .
        "\u{1}\u{0}\u{1ca}\u{1}\u{4}\u{0}\u{c}\u{1}\u{5}\u{0}\u{1}\u{6}\u{8}\u{0}\u{5}\u{1}" .
        "\u{7}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{11}\u{0}\u{70}\u{3}\u{5}\u{1}\u{1}\u{0}" .
        "\u{2}\u{1}\u{2}\u{0}\u{4}\u{1}\u{1}\u{7}\u{7}\u{0}\u{1}\u{1}\u{1}\u{6}\u{3}\u{1}" .
        "\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{14}\u{1}\u{1}\u{0}\u{53}\u{1}\u{1}\u{0}\u{8b}\u{1}" .
        "\u{1}\u{0}\u{7}\u{3}\u{9e}\u{1}\u{9}\u{0}\u{26}\u{1}\u{2}\u{0}\u{1}\u{1}\u{7}\u{0}" .
        "\u{27}\u{1}\u{1}\u{0}\u{1}\u{7}\u{7}\u{0}\u{2d}\u{3}\u{1}\u{0}\u{1}\u{3}\u{1}\u{0}" .
        "\u{2}\u{3}\u{1}\u{0}\u{2}\u{3}\u{1}\u{0}\u{1}\u{3}\u{8}\u{0}\u{1b}\u{e}\u{5}\u{0}" .
        "\u{3}\u{e}\u{1}\u{1}\u{1}\u{6}\u{b}\u{0}\u{5}\u{3}\u{7}\u{0}\u{2}\u{7}\u{2}\u{0}" .
        "\u{b}\u{3}\u{1}\u{0}\u{1}\u{3}\u{3}\u{0}\u{2b}\u{1}\u{15}\u{3}\u{a}\u{4}\u{1}\u{0}" .
        "\u{1}\u{4}\u{1}\u{7}\u{1}\u{0}\u{2}\u{1}\u{1}\u{3}\u{63}\u{1}\u{1}\u{0}\u{1}\u{1}" .
        "\u{8}\u{3}\u{1}\u{0}\u{6}\u{3}\u{2}\u{1}\u{2}\u{3}\u{1}\u{0}\u{4}\u{3}\u{2}\u{1}" .
        "\u{a}\u{4}\u{3}\u{1}\u{2}\u{0}\u{1}\u{1}\u{f}\u{0}\u{1}\u{3}\u{1}\u{1}\u{1}\u{3}" .
        "\u{1e}\u{1}\u{1b}\u{3}\u{2}\u{0}\u{59}\u{1}\u{b}\u{3}\u{1}\u{1}\u{e}\u{0}\u{a}\u{4}" .
        "\u{21}\u{1}\u{9}\u{3}\u{2}\u{1}\u{2}\u{0}\u{1}\u{7}\u{1}\u{0}\u{1}\u{1}\u{5}\u{0}" .
        "\u{16}\u{1}\u{4}\u{3}\u{1}\u{1}\u{9}\u{3}\u{1}\u{1}\u{3}\u{3}\u{1}\u{1}\u{5}\u{3}" .
        "\u{12}\u{0}\u{19}\u{1}\u{3}\u{3}\u{44}\u{0}\u{1}\u{1}\u{1}\u{0}\u{b}\u{1}\u{37}\u{0}" .
        "\u{1b}\u{3}\u{1}\u{0}\u{4}\u{3}\u{36}\u{1}\u{3}\u{3}\u{1}\u{1}\u{12}\u{3}\u{1}\u{1}" .
        "\u{7}\u{3}\u{a}\u{1}\u{2}\u{3}\u{2}\u{0}\u{a}\u{4}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}" .
        "\u{7}\u{1}\u{1}\u{0}\u{3}\u{3}\u{1}\u{0}\u{8}\u{1}\u{2}\u{0}\u{2}\u{1}\u{2}\u{0}" .
        "\u{16}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{1}\u{1}\u{3}\u{0}\u{4}\u{1}\u{2}\u{0}" .
        "\u{1}\u{3}\u{1}\u{1}\u{7}\u{3}\u{2}\u{0}\u{2}\u{3}\u{2}\u{0}\u{3}\u{3}\u{1}\u{1}" .
        "\u{8}\u{0}\u{1}\u{3}\u{4}\u{0}\u{2}\u{1}\u{1}\u{0}\u{3}\u{1}\u{2}\u{3}\u{2}\u{0}" .
        "\u{a}\u{4}\u{2}\u{1}\u{f}\u{0}\u{3}\u{3}\u{1}\u{0}\u{6}\u{1}\u{4}\u{0}\u{2}\u{1}" .
        "\u{2}\u{0}\u{16}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{2}\u{1}\u{1}\u{0}\u{2}\u{1}" .
        "\u{1}\u{0}\u{2}\u{1}\u{2}\u{0}\u{1}\u{3}\u{1}\u{0}\u{5}\u{3}\u{4}\u{0}\u{2}\u{3}" .
        "\u{2}\u{0}\u{3}\u{3}\u{3}\u{0}\u{1}\u{3}\u{7}\u{0}\u{4}\u{1}\u{1}\u{0}\u{1}\u{1}" .
        "\u{7}\u{0}\u{a}\u{4}\u{2}\u{3}\u{3}\u{1}\u{1}\u{3}\u{b}\u{0}\u{3}\u{3}\u{1}\u{0}" .
        "\u{9}\u{1}\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{16}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}" .
        "\u{2}\u{1}\u{1}\u{0}\u{5}\u{1}\u{2}\u{0}\u{1}\u{3}\u{1}\u{1}\u{8}\u{3}\u{1}\u{0}" .
        "\u{3}\u{3}\u{1}\u{0}\u{3}\u{3}\u{2}\u{0}\u{1}\u{1}\u{f}\u{0}\u{2}\u{1}\u{2}\u{3}" .
        "\u{2}\u{0}\u{a}\u{4}\u{11}\u{0}\u{3}\u{3}\u{1}\u{0}\u{8}\u{1}\u{2}\u{0}\u{2}\u{1}" .
        "\u{2}\u{0}\u{16}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{2}\u{1}\u{1}\u{0}\u{5}\u{1}" .
        "\u{2}\u{0}\u{1}\u{3}\u{1}\u{1}\u{7}\u{3}\u{2}\u{0}\u{2}\u{3}\u{2}\u{0}\u{3}\u{3}" .
        "\u{8}\u{0}\u{2}\u{3}\u{4}\u{0}\u{2}\u{1}\u{1}\u{0}\u{3}\u{1}\u{2}\u{3}\u{2}\u{0}" .
        "\u{a}\u{4}\u{1}\u{0}\u{1}\u{1}\u{10}\u{0}\u{1}\u{3}\u{1}\u{1}\u{1}\u{0}\u{6}\u{1}" .
        "\u{3}\u{0}\u{3}\u{1}\u{1}\u{0}\u{4}\u{1}\u{3}\u{0}\u{2}\u{1}\u{1}\u{0}\u{1}\u{1}" .
        "\u{1}\u{0}\u{2}\u{1}\u{3}\u{0}\u{2}\u{1}\u{3}\u{0}\u{3}\u{1}\u{3}\u{0}\u{c}\u{1}" .
        "\u{4}\u{0}\u{5}\u{3}\u{3}\u{0}\u{3}\u{3}\u{1}\u{0}\u{4}\u{3}\u{2}\u{0}\u{1}\u{1}" .
        "\u{6}\u{0}\u{1}\u{3}\u{e}\u{0}\u{a}\u{4}\u{11}\u{0}\u{3}\u{3}\u{1}\u{0}\u{8}\u{1}" .
        "\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{17}\u{1}\u{1}\u{0}\u{a}\u{1}\u{1}\u{0}\u{5}\u{1}" .
        "\u{3}\u{0}\u{1}\u{1}\u{7}\u{3}\u{1}\u{0}\u{3}\u{3}\u{1}\u{0}\u{4}\u{3}\u{7}\u{0}" .
        "\u{2}\u{3}\u{1}\u{0}\u{2}\u{1}\u{6}\u{0}\u{2}\u{1}\u{2}\u{3}\u{2}\u{0}\u{a}\u{4}" .
        "\u{12}\u{0}\u{2}\u{3}\u{1}\u{0}\u{8}\u{1}\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{17}\u{1}" .
        "\u{1}\u{0}\u{a}\u{1}\u{1}\u{0}\u{5}\u{1}\u{2}\u{0}\u{1}\u{3}\u{1}\u{1}\u{7}\u{3}" .
        "\u{1}\u{0}\u{3}\u{3}\u{1}\u{0}\u{4}\u{3}\u{7}\u{0}\u{2}\u{3}\u{7}\u{0}\u{1}\u{1}" .
        "\u{1}\u{0}\u{2}\u{1}\u{2}\u{3}\u{2}\u{0}\u{a}\u{4}\u{1}\u{0}\u{2}\u{1}\u{f}\u{0}" .
        "\u{2}\u{3}\u{1}\u{0}\u{8}\u{1}\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{29}\u{1}\u{2}\u{0}" .
        "\u{1}\u{1}\u{7}\u{3}\u{1}\u{0}\u{3}\u{3}\u{1}\u{0}\u{4}\u{3}\u{1}\u{1}\u{8}\u{0}" .
        "\u{1}\u{3}\u{8}\u{0}\u{2}\u{1}\u{2}\u{3}\u{2}\u{0}\u{a}\u{4}\u{a}\u{0}\u{6}\u{1}" .
        "\u{2}\u{0}\u{2}\u{3}\u{1}\u{0}\u{12}\u{1}\u{3}\u{0}\u{18}\u{1}\u{1}\u{0}\u{9}\u{1}" .
        "\u{1}\u{0}\u{1}\u{1}\u{2}\u{0}\u{7}\u{1}\u{3}\u{0}\u{1}\u{3}\u{4}\u{0}\u{6}\u{3}" .
        "\u{1}\u{0}\u{1}\u{3}\u{1}\u{0}\u{8}\u{3}\u{12}\u{0}\u{2}\u{3}\u{d}\u{0}\u{30}\u{10}" .
        "\u{1}\u{11}\u{2}\u{10}\u{7}\u{11}\u{5}\u{0}\u{7}\u{10}\u{8}\u{11}\u{1}\u{0}\u{a}\u{4}" .
        "\u{27}\u{0}\u{2}\u{10}\u{1}\u{0}\u{1}\u{10}\u{2}\u{0}\u{2}\u{10}\u{1}\u{0}\u{1}\u{10}" .
        "\u{2}\u{0}\u{1}\u{10}\u{6}\u{0}\u{4}\u{10}\u{1}\u{0}\u{7}\u{10}\u{1}\u{0}\u{3}\u{10}" .
        "\u{1}\u{0}\u{1}\u{10}\u{1}\u{0}\u{1}\u{10}\u{2}\u{0}\u{2}\u{10}\u{1}\u{0}\u{4}\u{10}" .
        "\u{1}\u{11}\u{2}\u{10}\u{6}\u{11}\u{1}\u{0}\u{2}\u{11}\u{1}\u{10}\u{2}\u{0}\u{5}\u{10}" .
        "\u{1}\u{0}\u{1}\u{10}\u{1}\u{0}\u{6}\u{11}\u{2}\u{0}\u{a}\u{4}\u{2}\u{0}\u{4}\u{10}" .
        "\u{20}\u{0}\u{1}\u{1}\u{17}\u{0}\u{2}\u{3}\u{6}\u{0}\u{a}\u{4}\u{b}\u{0}\u{1}\u{3}" .
        "\u{1}\u{0}\u{1}\u{3}\u{1}\u{0}\u{1}\u{3}\u{4}\u{0}\u{2}\u{3}\u{8}\u{1}\u{1}\u{0}" .
        "\u{24}\u{1}\u{4}\u{0}\u{14}\u{3}\u{1}\u{0}\u{2}\u{3}\u{5}\u{1}\u{b}\u{3}\u{1}\u{0}" .
        "\u{24}\u{3}\u{9}\u{0}\u{1}\u{3}\u{39}\u{0}\u{2b}\u{10}\u{14}\u{11}\u{1}\u{10}\u{a}\u{4}" .
        "\u{6}\u{0}\u{6}\u{10}\u{4}\u{11}\u{4}\u{10}\u{3}\u{11}\u{1}\u{10}\u{3}\u{11}\u{2}\u{10}" .
        "\u{7}\u{11}\u{3}\u{10}\u{4}\u{11}\u{d}\u{10}\u{c}\u{11}\u{1}\u{10}\u{1}\u{11}\u{a}\u{4}" .
        "\u{4}\u{11}\u{2}\u{10}\u{26}\u{1}\u{1}\u{0}\u{1}\u{1}\u{5}\u{0}\u{1}\u{1}\u{2}\u{0}" .
        "\u{2b}\u{1}\u{1}\u{0}\u{4}\u{1}\u{100}\u{2}\u{49}\u{1}\u{1}\u{0}\u{4}\u{1}\u{2}\u{0}" .
        "\u{7}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{4}\u{1}\u{2}\u{0}\u{29}\u{1}\u{1}\u{0}" .
        "\u{4}\u{1}\u{2}\u{0}\u{21}\u{1}\u{1}\u{0}\u{4}\u{1}\u{2}\u{0}\u{7}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{1}\u{0}\u{4}\u{1}\u{2}\u{0}\u{f}\u{1}\u{1}\u{0}\u{39}\u{1}\u{1}\u{0}" .
        "\u{4}\u{1}\u{2}\u{0}\u{43}\u{1}\u{2}\u{0}\u{3}\u{3}\u{20}\u{0}\u{10}\u{1}\u{10}\u{0}" .
        "\u{55}\u{1}\u{c}\u{0}\u{26c}\u{1}\u{2}\u{0}\u{11}\u{1}\u{1}\u{0}\u{1a}\u{1}\u{5}\u{0}" .
        "\u{4b}\u{1}\u{3}\u{0}\u{3}\u{1}\u{f}\u{0}\u{d}\u{1}\u{1}\u{0}\u{4}\u{1}\u{3}\u{3}" .
        "\u{b}\u{0}\u{12}\u{1}\u{3}\u{3}\u{b}\u{0}\u{12}\u{1}\u{2}\u{3}\u{c}\u{0}\u{d}\u{1}" .
        "\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{2}\u{3}\u{c}\u{0}\u{34}\u{10}\u{20}\u{11}\u{3}\u{0}" .
        "\u{1}\u{10}\u{4}\u{0}\u{1}\u{10}\u{1}\u{11}\u{2}\u{0}\u{a}\u{4}\u{21}\u{0}\u{4}\u{3}" .
        "\u{1}\u{0}\u{a}\u{4}\u{6}\u{0}\u{58}\u{1}\u{8}\u{0}\u{29}\u{1}\u{1}\u{3}\u{1}\u{1}" .
        "\u{5}\u{0}\u{46}\u{1}\u{a}\u{0}\u{1d}\u{1}\u{3}\u{0}\u{c}\u{3}\u{4}\u{0}\u{c}\u{3}" .
        "\u{a}\u{0}\u{a}\u{4}\u{1e}\u{10}\u{2}\u{0}\u{5}\u{10}\u{b}\u{0}\u{2c}\u{10}\u{4}\u{0}" .
        "\u{11}\u{11}\u{7}\u{10}\u{2}\u{11}\u{6}\u{0}\u{a}\u{4}\u{1}\u{10}\u{3}\u{0}\u{2}\u{10}" .
        "\u{20}\u{0}\u{17}\u{1}\u{5}\u{3}\u{4}\u{0}\u{35}\u{10}\u{a}\u{11}\u{1}\u{0}\u{1d}\u{11}" .
        "\u{2}\u{0}\u{1}\u{3}\u{a}\u{4}\u{6}\u{0}\u{a}\u{4}\u{6}\u{0}\u{e}\u{10}\u{52}\u{0}" .
        "\u{5}\u{3}\u{2f}\u{1}\u{11}\u{3}\u{7}\u{1}\u{4}\u{0}\u{a}\u{4}\u{11}\u{0}\u{9}\u{3}" .
        "\u{c}\u{0}\u{3}\u{3}\u{1e}\u{1}\u{d}\u{3}\u{2}\u{1}\u{a}\u{4}\u{2c}\u{1}\u{e}\u{3}" .
        "\u{c}\u{0}\u{24}\u{1}\u{14}\u{3}\u{8}\u{0}\u{a}\u{4}\u{3}\u{0}\u{3}\u{1}\u{a}\u{4}" .
        "\u{24}\u{1}\u{52}\u{0}\u{3}\u{3}\u{1}\u{0}\u{15}\u{3}\u{4}\u{1}\u{1}\u{3}\u{4}\u{1}" .
        "\u{3}\u{3}\u{2}\u{1}\u{9}\u{0}\u{c0}\u{1}\u{27}\u{3}\u{15}\u{0}\u{4}\u{3}\u{116}\u{1}" .
        "\u{2}\u{0}\u{6}\u{1}\u{2}\u{0}\u{26}\u{1}\u{2}\u{0}\u{6}\u{1}\u{2}\u{0}\u{8}\u{1}" .
        "\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1f}\u{1}" .
        "\u{2}\u{0}\u{35}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{1}\u{1}\u{3}\u{0}\u{3}\u{1}" .
        "\u{1}\u{0}\u{7}\u{1}\u{3}\u{0}\u{4}\u{1}\u{2}\u{0}\u{6}\u{1}\u{4}\u{0}\u{d}\u{1}" .
        "\u{5}\u{0}\u{3}\u{1}\u{1}\u{0}\u{7}\u{1}\u{f}\u{0}\u{4}\u{3}\u{8}\u{0}\u{2}\u{8}" .
        "\u{a}\u{0}\u{1}\u{8}\u{2}\u{0}\u{1}\u{6}\u{2}\u{0}\u{5}\u{3}\u{10}\u{0}\u{2}\u{9}" .
        "\u{3}\u{0}\u{1}\u{7}\u{f}\u{0}\u{1}\u{9}\u{b}\u{0}\u{5}\u{3}\u{1}\u{0}\u{a}\u{3}" .
        "\u{1}\u{0}\u{1}\u{1}\u{d}\u{0}\u{1}\u{1}\u{10}\u{0}\u{d}\u{1}\u{33}\u{0}\u{21}\u{3}" .
        "\u{11}\u{0}\u{1}\u{1}\u{4}\u{0}\u{1}\u{1}\u{2}\u{0}\u{a}\u{1}\u{1}\u{0}\u{1}\u{1}" .
        "\u{3}\u{0}\u{5}\u{1}\u{6}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}" .
        "\u{1}\u{0}\u{4}\u{1}\u{1}\u{0}\u{b}\u{1}\u{2}\u{0}\u{4}\u{1}\u{5}\u{0}\u{5}\u{1}" .
        "\u{4}\u{0}\u{1}\u{1}\u{11}\u{0}\u{29}\u{1}\u{32d}\u{0}\u{34}\u{1}\u{716}\u{0}\u{2f}\u{1}" .
        "\u{1}\u{0}\u{2f}\u{1}\u{1}\u{0}\u{85}\u{1}\u{6}\u{0}\u{4}\u{1}\u{3}\u{3}\u{2}\u{1}" .
        "\u{c}\u{0}\u{26}\u{1}\u{1}\u{0}\u{1}\u{1}\u{5}\u{0}\u{1}\u{1}\u{2}\u{0}\u{38}\u{1}" .
        "\u{7}\u{0}\u{1}\u{1}\u{f}\u{0}\u{1}\u{3}\u{17}\u{1}\u{9}\u{0}\u{7}\u{1}\u{1}\u{0}" .
        "\u{7}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}" .
        "\u{7}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{20}\u{3}\u{2f}\u{0}" .
        "\u{1}\u{1}\u{50}\u{0}\u{1a}\u{a}\u{1}\u{0}\u{59}\u{a}\u{c}\u{0}\u{d6}\u{a}\u{2f}\u{0}" .
        "\u{1}\u{1}\u{1}\u{0}\u{1}\u{a}\u{19}\u{0}\u{9}\u{a}\u{6}\u{3}\u{1}\u{0}\u{5}\u{5}" .
        "\u{2}\u{0}\u{3}\u{a}\u{1}\u{1}\u{1}\u{1}\u{4}\u{0}\u{56}\u{b}\u{2}\u{0}\u{2}\u{3}" .
        "\u{2}\u{5}\u{3}\u{b}\u{5b}\u{5}\u{1}\u{0}\u{4}\u{5}\u{5}\u{0}\u{29}\u{1}\u{3}\u{0}" .
        "\u{5e}\u{2}\u{11}\u{0}\u{1b}\u{1}\u{35}\u{0}\u{10}\u{5}\u{d0}\u{0}\u{2f}\u{5}\u{1}\u{0}" .
        "\u{58}\u{5}\u{a8}\u{0}\u{19b6}\u{a}\u{4a}\u{0}\u{51cd}\u{a}\u{33}\u{0}\u{48d}\u{1}\u{43}\u{0}" .
        "\u{2e}\u{1}\u{2}\u{0}\u{10d}\u{1}\u{3}\u{0}\u{10}\u{1}\u{a}\u{4}\u{2}\u{1}\u{14}\u{0}" .
        "\u{2f}\u{1}\u{4}\u{3}\u{1}\u{0}\u{a}\u{3}\u{1}\u{0}\u{19}\u{1}\u{7}\u{0}\u{1}\u{3}" .
        "\u{50}\u{1}\u{2}\u{3}\u{25}\u{0}\u{9}\u{1}\u{2}\u{0}\u{67}\u{1}\u{2}\u{0}\u{4}\u{1}" .
        "\u{1}\u{0}\u{4}\u{1}\u{c}\u{0}\u{b}\u{1}\u{4d}\u{0}\u{a}\u{1}\u{1}\u{3}\u{3}\u{1}" .
        "\u{1}\u{3}\u{4}\u{1}\u{1}\u{3}\u{17}\u{1}\u{5}\u{3}\u{18}\u{0}\u{34}\u{1}\u{c}\u{0}" .
        "\u{2}\u{3}\u{32}\u{1}\u{11}\u{3}\u{b}\u{0}\u{a}\u{4}\u{6}\u{0}\u{12}\u{3}\u{6}\u{1}" .
        "\u{3}\u{0}\u{1}\u{1}\u{4}\u{0}\u{a}\u{4}\u{1c}\u{1}\u{8}\u{3}\u{2}\u{0}\u{17}\u{1}" .
        "\u{d}\u{3}\u{c}\u{0}\u{1d}\u{2}\u{3}\u{0}\u{4}\u{3}\u{2f}\u{1}\u{e}\u{3}\u{e}\u{0}" .
        "\u{1}\u{1}\u{a}\u{4}\u{26}\u{0}\u{29}\u{1}\u{e}\u{3}\u{9}\u{0}\u{3}\u{1}\u{1}\u{3}" .
        "\u{8}\u{1}\u{2}\u{3}\u{2}\u{0}\u{a}\u{4}\u{6}\u{0}\u{1b}\u{10}\u{1}\u{11}\u{4}\u{0}" .
        "\u{30}\u{10}\u{1}\u{11}\u{1}\u{10}\u{3}\u{11}\u{2}\u{10}\u{2}\u{11}\u{5}\u{10}\u{2}\u{11}" .
        "\u{1}\u{10}\u{1}\u{11}\u{1}\u{10}\u{18}\u{0}\u{5}\u{10}\u{b}\u{1}\u{5}\u{3}\u{2}\u{0}" .
        "\u{3}\u{1}\u{2}\u{3}\u{a}\u{0}\u{6}\u{1}\u{2}\u{0}\u{6}\u{1}\u{2}\u{0}\u{6}\u{1}" .
        "\u{9}\u{0}\u{7}\u{1}\u{1}\u{0}\u{7}\u{1}\u{91}\u{0}\u{23}\u{1}\u{8}\u{3}\u{1}\u{0}" .
        "\u{2}\u{3}\u{2}\u{0}\u{a}\u{4}\u{6}\u{0}\u{2ba4}\u{2}\u{c}\u{0}\u{17}\u{2}\u{4}\u{0}" .
        "\u{31}\u{2}\u{2104}\u{0}\u{16e}\u{a}\u{2}\u{0}\u{6a}\u{a}\u{26}\u{0}\u{7}\u{1}\u{c}\u{0}" .
        "\u{5}\u{1}\u{5}\u{0}\u{1}\u{e}\u{1}\u{3}\u{a}\u{e}\u{1}\u{0}\u{d}\u{e}\u{1}\u{0}" .
        "\u{5}\u{e}\u{1}\u{0}\u{1}\u{e}\u{1}\u{0}\u{2}\u{e}\u{1}\u{0}\u{2}\u{e}\u{1}\u{0}" .
        "\u{a}\u{e}\u{62}\u{1}\u{21}\u{0}\u{16b}\u{1}\u{12}\u{0}\u{40}\u{1}\u{2}\u{0}\u{36}\u{1}" .
        "\u{28}\u{0}\u{c}\u{1}\u{4}\u{0}\u{10}\u{3}\u{1}\u{7}\u{2}\u{0}\u{1}\u{6}\u{1}\u{7}" .
        "\u{b}\u{0}\u{7}\u{3}\u{c}\u{0}\u{2}\u{9}\u{18}\u{0}\u{3}\u{9}\u{1}\u{7}\u{1}\u{0}" .
        "\u{1}\u{8}\u{1}\u{0}\u{1}\u{7}\u{1}\u{6}\u{1a}\u{0}\u{5}\u{1}\u{1}\u{0}\u{87}\u{1}" .
        "\u{2}\u{0}\u{1}\u{3}\u{7}\u{0}\u{1}\u{8}\u{4}\u{0}\u{1}\u{7}\u{1}\u{0}\u{1}\u{8}" .
        "\u{1}\u{0}\u{a}\u{4}\u{1}\u{6}\u{1}\u{7}\u{5}\u{0}\u{1a}\u{1}\u{4}\u{0}\u{1}\u{9}" .
        "\u{1}\u{0}\u{1a}\u{1}\u{b}\u{0}\u{38}\u{5}\u{2}\u{3}\u{1f}\u{2}\u{3}\u{0}\u{6}\u{2}" .
        "\u{2}\u{0}\u{6}\u{2}\u{2}\u{0}\u{6}\u{2}\u{2}\u{0}\u{3}\u{2}\u{1c}\u{0}\u{3}\u{3}" .
        "\u{4}\u{0}\u{c}\u{1}\u{1}\u{0}\u{1a}\u{1}\u{1}\u{0}\u{13}\u{1}\u{1}\u{0}\u{2}\u{1}" .
        "\u{1}\u{0}\u{f}\u{1}\u{2}\u{0}\u{e}\u{1}\u{22}\u{0}\u{7b}\u{1}\u{45}\u{0}\u{35}\u{1}" .
        "\u{88}\u{0}\u{1}\u{3}\u{82}\u{0}\u{1d}\u{1}\u{3}\u{0}\u{31}\u{1}\u{2f}\u{0}\u{1f}\u{1}" .
        "\u{11}\u{0}\u{1b}\u{1}\u{35}\u{0}\u{1e}\u{1}\u{2}\u{0}\u{24}\u{1}\u{4}\u{0}\u{8}\u{1}" .
        "\u{1}\u{0}\u{5}\u{1}\u{2a}\u{0}\u{9e}\u{1}\u{2}\u{0}\u{a}\u{4}\u{356}\u{0}\u{6}\u{1}" .
        "\u{2}\u{0}\u{1}\u{1}\u{1}\u{0}\u{2c}\u{1}\u{1}\u{0}\u{2}\u{1}\u{3}\u{0}\u{1}\u{1}" .
        "\u{2}\u{0}\u{17}\u{1}\u{aa}\u{0}\u{16}\u{1}\u{a}\u{0}\u{1a}\u{1}\u{46}\u{0}\u{38}\u{1}" .
        "\u{6}\u{0}\u{2}\u{1}\u{40}\u{0}\u{1}\u{1}\u{3}\u{3}\u{1}\u{0}\u{2}\u{3}\u{5}\u{0}" .
        "\u{4}\u{3}\u{4}\u{1}\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{1b}\u{1}\u{4}\u{0}\u{3}\u{3}" .
        "\u{4}\u{0}\u{1}\u{3}\u{20}\u{0}\u{1d}\u{1}\u{83}\u{0}\u{36}\u{1}\u{a}\u{0}\u{16}\u{1}" .
        "\u{a}\u{0}\u{13}\u{1}\u{8d}\u{0}\u{49}\u{1}\u{3b7}\u{0}\u{3}\u{3}\u{35}\u{1}\u{f}\u{3}" .
        "\u{1f}\u{0}\u{a}\u{4}\u{10}\u{0}\u{3}\u{3}\u{2d}\u{1}\u{b}\u{3}\u{2}\u{0}\u{1}\u{3}" .
        "\u{12}\u{0}\u{19}\u{1}\u{7}\u{0}\u{a}\u{4}\u{6}\u{0}\u{3}\u{3}\u{24}\u{1}\u{e}\u{3}" .
        "\u{1}\u{0}\u{a}\u{4}\u{40}\u{0}\u{3}\u{3}\u{30}\u{1}\u{e}\u{3}\u{4}\u{1}\u{b}\u{0}" .
        "\u{a}\u{4}\u{4a6}\u{0}\u{2b}\u{1}\u{d}\u{3}\u{8}\u{0}\u{a}\u{4}\u{936}\u{0}\u{36f}\u{1}" .
        "\u{91}\u{0}\u{63}\u{1}\u{b9d}\u{0}\u{42f}\u{1}\u{33d1}\u{0}\u{239}\u{1}\u{4c7}\u{0}\u{45}\u{1}" .
        "\u{b}\u{0}\u{1}\u{1}\u{2e}\u{3}\u{10}\u{0}\u{4}\u{3}\u{d}\u{1}\u{4060}\u{0}\u{1}\u{5}" .
        "\u{1}\u{b}\u{2163}\u{0}\u{5}\u{3}\u{3}\u{0}\u{16}\u{3}\u{2}\u{0}\u{7}\u{3}\u{1e}\u{0}" .
        "\u{4}\u{3}\u{94}\u{0}\u{3}\u{3}\u{1bb}\u{0}\u{55}\u{1}\u{1}\u{0}\u{47}\u{1}\u{1}\u{0}" .
        "\u{2}\u{1}\u{2}\u{0}\u{1}\u{1}\u{2}\u{0}\u{2}\u{1}\u{2}\u{0}\u{4}\u{1}\u{1}\u{0}" .
        "\u{c}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{41}\u{1}\u{1}\u{0}" .
        "\u{4}\u{1}\u{2}\u{0}\u{8}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{1c}\u{1}\u{1}\u{0}" .
        "\u{4}\u{1}\u{1}\u{0}\u{5}\u{1}\u{1}\u{0}\u{1}\u{1}\u{3}\u{0}\u{7}\u{1}\u{1}\u{0}" .
        "\u{154}\u{1}\u{2}\u{0}\u{19}\u{1}\u{1}\u{0}\u{19}\u{1}\u{1}\u{0}\u{1f}\u{1}\u{1}\u{0}" .
        "\u{19}\u{1}\u{1}\u{0}\u{1f}\u{1}\u{1}\u{0}\u{19}\u{1}\u{1}\u{0}\u{1f}\u{1}\u{1}\u{0}" .
        "\u{19}\u{1}\u{1}\u{0}\u{1f}\u{1}\u{1}\u{0}\u{19}\u{1}\u{1}\u{0}\u{8}\u{1}\u{2}\u{0}" .
        "\u{32}\u{4}\u{1600}\u{0}\u{4}\u{1}\u{1}\u{0}\u{1b}\u{1}\u{1}\u{0}\u{2}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{2}\u{0}\u{1}\u{1}\u{1}\u{0}\u{a}\u{1}\u{1}\u{0}\u{4}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{6}\u{0}\u{1}\u{1}\u{4}\u{0}\u{1}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{3}\u{1}\u{1}\u{0}\u{2}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{2}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{2}\u{1}\u{1}\u{0}\u{1}\u{1}\u{2}\u{0}" .
        "\u{4}\u{1}\u{1}\u{0}\u{7}\u{1}\u{1}\u{0}\u{4}\u{1}\u{1}\u{0}\u{4}\u{1}\u{1}\u{0}" .
        "\u{1}\u{1}\u{1}\u{0}\u{a}\u{1}\u{1}\u{0}\u{11}\u{1}\u{5}\u{0}\u{3}\u{1}\u{1}\u{0}" .
        "\u{5}\u{1}\u{1}\u{0}\u{11}\u{1}\u{32a}\u{0}\u{1a}\u{f}\u{1}\u{b}\u{dff}\u{0}\u{a6d7}\u{a}" .
        "\u{29}\u{0}\u{1035}\u{a}\u{b}\u{0}\u{de}\u{a}\u{3fe2}\u{0}\u{21e}\u{a}\u{ffff}\u{0}\u{ffff}\u{0}" .
        "\u{ffff}\u{0}\u{ffff}\u{0}\u{ffff}\u{0}\u{ffff}\u{0}\u{ffff}\u{0}\u{ffff}\u{0}\u{ffff}\u{0}\u{ffff}\u{0}" .
        "\u{ffff}\u{0}\u{5ee}\u{0}\u{1}\u{3}\u{1e}\u{0}\u{60}\u{3}\u{80}\u{0}\u{f0}\u{3}\u{ffff}\u{0}" .
        "\u{ffff}\u{0}\u{fe12}\u{0}";

    /**
     * Translates characters to character classes
     */
    public static $ZZ_CMAP;

    /**
     * Translates DFA states to action switch labels.
     */
    public static $ZZ_ACTION;

    private static $ZZ_ACTION_PACKED_0 =
        "\u{1}\u{0}\u{1}\u{1}\u{1}\u{2}\u{1}\u{3}\u{1}\u{4}\u{1}\u{5}\u{1}\u{1}\u{1}\u{6}" .
        "\u{1}\u{7}\u{1}\u{2}\u{1}\u{1}\u{1}\u{8}\u{1}\u{2}\u{1}\u{0}\u{1}\u{2}\u{1}\u{0}" .
        "\u{1}\u{4}\u{1}\u{0}\u{2}\u{2}\u{2}\u{0}\u{1}\u{1}\u{1}\u{0}";

    private static function zzUnpackAction(string $packed = null, $offset = 0, array &$result = [])
    {
        if ($packed === null) {
            $packed = self::$ZZ_ACTION_PACKED_0;
            $returnResult = true;
        } else {
            $returnResult = false;
        }
        $packed = preg_split('//u', $packed, null, PREG_SPLIT_NO_EMPTY);
        $i = 0;       /* index in packed string  */
        $j = $offset;  /* index in unpacked array */
        $l = count($packed);
        while ($i < $l) {
            $count = ord($packed[$i++]);
            $value = ord($packed[$i++]);
            do $result[$j++] = $value; while (--$count > 0);
        }

        return $returnResult ? $result : $j;
    }


    /**
     * Translates a state to a row index in the transition table
     */
    public static $ZZ_ROWMAP;

    private static $ZZ_ROWMAP_PACKED_0 =
        "\u{0}\u{0}\u{0}\u{12}\u{0}\u{24}\u{0}\u{36}\u{0}\u{48}\u{0}\u{5a}\u{0}\u{6c}\u{0}\u{7e}" .
        "\u{0}\u{90}\u{0}\u{a2}\u{0}\u{b4}\u{0}\u{c6}\u{0}\u{d8}\u{0}\u{ea}\u{0}\u{fc}\u{0}\u{10e}" .
        "\u{0}\u{120}\u{0}\u{6c}\u{0}\u{132}\u{0}\u{144}\u{0}\u{156}\u{0}\u{b4}\u{0}\u{168}\u{0}\u{17a}";

    private static function zzUnpackRowMap(string $packed = null, $offset = 0, array &$result = [])
    {
        if ($packed === null) {
            $packed = self::$ZZ_ROWMAP_PACKED_0;
            $returnResult = true;
        } else {
            $returnResult = false;
        }
        $packed = preg_split('//u', $packed, null, PREG_SPLIT_NO_EMPTY);
        $i = 0;  /* index in packed string  */
        $j = $offset;  /* index in unpacked array */
        $l = count($packed);
        while ($i < $l) {
            $high = ord($packed[$i++]) << 16;
            $result[$j++] = $high | IntlChar::ord($packed[$i++]);
        }
        return $returnResult ? $result : $j;
    }

    /**
     * The transition table of the DFA
     */
    public static $ZZ_TRANS;

    private static $ZZ_TRANS_PACKED_0 =
        "\u{1}\u{2}\u{1}\u{3}\u{1}\u{4}\u{1}\u{2}\u{1}\u{5}\u{1}\u{6}\u{3}\u{2}\u{1}\u{7}" .
        "\u{1}\u{8}\u{1}\u{9}\u{2}\u{2}\u{1}\u{a}\u{1}\u{b}\u{2}\u{c}\u{13}\u{0}\u{3}\u{3}" .
        "\u{1}\u{d}\u{1}\u{0}\u{1}\u{e}\u{1}\u{0}\u{1}\u{e}\u{1}\u{f}\u{2}\u{0}\u{1}\u{e}" .
        "\u{1}\u{0}\u{1}\u{a}\u{2}\u{0}\u{1}\u{3}\u{1}\u{0}\u{1}\u{3}\u{2}\u{4}\u{1}\u{d}" .
        "\u{1}\u{0}\u{1}\u{e}\u{1}\u{0}\u{1}\u{e}\u{1}\u{f}\u{2}\u{0}\u{1}\u{e}\u{1}\u{0}" .
        "\u{1}\u{a}\u{2}\u{0}\u{1}\u{4}\u{1}\u{0}\u{2}\u{3}\u{2}\u{5}\u{2}\u{0}\u{2}\u{10}" .
        "\u{1}\u{11}\u{2}\u{0}\u{1}\u{10}\u{1}\u{0}\u{1}\u{a}\u{2}\u{0}\u{1}\u{5}\u{3}\u{0}" .
        "\u{1}\u{6}\u{1}\u{0}\u{1}\u{6}\u{3}\u{0}\u{1}\u{f}\u{7}\u{0}\u{1}\u{6}\u{1}\u{0}" .
        "\u{2}\u{3}\u{1}\u{12}\u{1}\u{5}\u{1}\u{13}\u{3}\u{0}\u{1}\u{12}\u{4}\u{0}\u{1}\u{a}" .
        "\u{2}\u{0}\u{1}\u{12}\u{3}\u{0}\u{1}\u{8}\u{d}\u{0}\u{1}\u{8}\u{3}\u{0}\u{1}\u{9}" .
        "\u{d}\u{0}\u{1}\u{9}\u{1}\u{0}\u{2}\u{3}\u{1}\u{a}\u{1}\u{d}\u{1}\u{0}\u{1}\u{e}" .
        "\u{1}\u{0}\u{1}\u{e}\u{1}\u{f}\u{2}\u{0}\u{1}\u{14}\u{1}\u{15}\u{1}\u{a}\u{2}\u{0}" .
        "\u{1}\u{a}\u{3}\u{0}\u{1}\u{16}\u{b}\u{0}\u{1}\u{17}\u{1}\u{0}\u{1}\u{16}\u{3}\u{0}" .
        "\u{1}\u{c}\u{c}\u{0}\u{2}\u{c}\u{1}\u{0}\u{2}\u{3}\u{2}\u{d}\u{2}\u{0}\u{2}\u{18}" .
        "\u{1}\u{f}\u{2}\u{0}\u{1}\u{18}\u{1}\u{0}\u{1}\u{a}\u{2}\u{0}\u{1}\u{d}\u{1}\u{0}" .
        "\u{2}\u{3}\u{1}\u{e}\u{a}\u{0}\u{1}\u{3}\u{2}\u{0}\u{1}\u{e}\u{1}\u{0}\u{2}\u{3}" .
        "\u{1}\u{f}\u{1}\u{d}\u{1}\u{13}\u{3}\u{0}\u{1}\u{f}\u{4}\u{0}\u{1}\u{a}\u{2}\u{0}" .
        "\u{1}\u{f}\u{3}\u{0}\u{1}\u{10}\u{1}\u{5}\u{c}\u{0}\u{1}\u{10}\u{1}\u{0}\u{2}\u{3}" .
        "\u{1}\u{11}\u{1}\u{5}\u{1}\u{13}\u{3}\u{0}\u{1}\u{11}\u{4}\u{0}\u{1}\u{a}\u{2}\u{0}" .
        "\u{1}\u{11}\u{3}\u{0}\u{1}\u{13}\u{1}\u{0}\u{1}\u{13}\u{3}\u{0}\u{1}\u{f}\u{7}\u{0}" .
        "\u{1}\u{13}\u{1}\u{0}\u{2}\u{3}\u{1}\u{14}\u{1}\u{d}\u{4}\u{0}\u{1}\u{f}\u{4}\u{0}" .
        "\u{1}\u{a}\u{2}\u{0}\u{1}\u{14}\u{3}\u{0}\u{1}\u{15}\u{a}\u{0}\u{1}\u{14}\u{2}\u{0}" .
        "\u{1}\u{15}\u{3}\u{0}\u{1}\u{17}\u{b}\u{0}\u{1}\u{17}\u{1}\u{0}\u{1}\u{17}\u{3}\u{0}" .
        "\u{1}\u{18}\u{1}\u{d}\u{c}\u{0}\u{1}\u{18}";

    private static function zzUnpackTrans(string $packed = null, $offset = 0, array &$result = [])
    {
        if ($packed === null) {
            $packed = self::$ZZ_TRANS_PACKED_0;
            $returnResult = true;
        } else {
            $returnResult = false;
        }
        $packed = preg_split('//u', $packed, null, PREG_SPLIT_NO_EMPTY);
        $i = 0;       /* index in packed string  */
        $j = $offset;  /* index in unpacked array */
        $l = count($packed);
        while ($i < $l) {
            $count = ord($packed[$i++]);
            $value = ord($packed[$i++]);
            $value--;
            do $result[$j++] = $value; while (--$count > 0);
        }
        return $returnResult ? $result : $j;
    }


    /* error codes */
    private static $ZZ_UNKNOWN_ERROR = 0;
    private static $ZZ_NO_MATCH = 1;
    private static $ZZ_PUSHBACK_2BIG = 2;

    /* error messages for the codes above */
    private static $ZZ_ERROR_MSG = [
        "Unkown internal scanner error",
        "Error: could not match input",
        "Error: pushback value was too large"
    ];

    /**
     * ZZ_ATTRIBUTE[aState] contains the attributes of state <code>aState</code>
     */
    public static $ZZ_ATTRIBUTE;

    private static $ZZ_ATTRIBUTE_PACKED_0 =
        "\u{1}\u{0}\u{1}\u{9}\u{b}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}\u{1}\u{1}\u{1}\u{0}" .
        "\u{2}\u{1}\u{2}\u{0}\u{1}\u{1}\u{1}\u{0}";


    private static function zzUnpackAttribute(string $packed = null, $offset = 0, array &$result = [])
    {
        if ($packed === null) {
            $packed = self::$ZZ_ATTRIBUTE_PACKED_0;
            $returnResult = true;
        } else {
            $returnResult = false;
        }
        $packed = preg_split('//u', $packed, null, PREG_SPLIT_NO_EMPTY);
        $i = 0;       /* index in packed string  */
        $j = $offset;  /* index in unpacked array */
        $l = count($packed);
        while ($i < $l) {
            $count = ord($packed[$i++]);
            $value = ord($packed[$i++]);
            do $result[$j++] = $value; while (--$count > 0);
        }
        return $returnResult ? $result : $j;
    }

    /**
     * the input device
     * @var Reader
     */
    private $zzReader;

    /** the current state of the DFA */
    private $zzState;

    /** the current lexical state */
    private $zzLexicalState = 0; //self::$YYINITIAL;

    /** this buffer contains the current text to be matched and is
     * the source of the yytext() string */
    private $zzBuffer = [];

    /** the textposition at the last accepting state */
    private $zzMarkedPos = 0;

    /** the current text position in the buffer */
    private $zzCurrentPos = 0;

    /** startRead marks the beginning of the yytext() string in the buffer */
    private $zzStartRead;

    /** endRead marks the last character in the buffer, that has been read
     * from input */
    private $zzEndRead;

    /** number of newlines encountered up to the start of the matched text */
    private $yyline;

    /** the number of characters up to the start of the matched text */
    private $yychar;

    /**
     * the number of characters from the last newline up to the start of the
     * matched text
     */
    private $yycolumn;

    /**
     * zzAtBOL == true <=> the scanner is currently at the beginning of a line
     */
    private $zzAtBOL = true;

    /** zzAtEOF == true <=> the scanner is at the EOF */
    private $zzAtEOF;

    /** denotes if the user-EOF-code has already been executed */
    private $zzEOFDone;

    /**
     * The number of occupied positions in zzBuffer beyond zzEndRead.
     * When a lead/high surrogate has been read from the input stream
     * into the final zzBuffer position, this will have a value of 1;
     * otherwise, it will have a value of 0.
     */
    private $zzFinalHighSurrogate = 0;

    /* user code: */
    /** Alphanumeric sequences */
    public static $WORD_TYPE = self::ALPHANUM;

    /** Numbers */
    public static $NUMERIC_TYPE = self::NUM;

    /**
     * Chars in class \p{Line_Break = Complex_Context} are from South East Asian
     * scripts (Thai, Lao, Myanmar, Khmer, etc.).  Sequences of these are kept
     * together as as a single token rather than broken up, because the logic
     * required to break them at word boundaries is too complex for UAX#29.
     * <p>
     * See Unicode Line Breaking Algorithm: http://www.unicode.org/reports/tr14/#SA
     */
    public static $SOUTH_EAST_ASIAN_TYPE = self::SOUTHEAST_ASIAN;

    /** Idiographic token type */
    public static $IDEOGRAPHIC_TYPE = self::IDEOGRAPHIC;

    /** Hiragana token type */
    public static $HIRAGANA_TYPE = self::HIRAGANA;

    /** Katakana token type */
    public static $KATAKANA_TYPE = self::KATAKANA;

    /** Hangul token type */
    public static $HANGUL_TYPE = self::HANGUL;

    /** Character count processed so far */
    public final function yychar(): int
    {
        return $this->yychar;
    }

    /**
     * Fills CharTermAttribute with the current token text.
     *
     * @param $t
     */
    public final function getText(&$t)
    {
        $t = implode('', array_slice($this->zzBuffer, $this->zzStartRead, $this->zzMarkedPos - $this->zzStartRead));
    }

    /**
     * Sets the scanner buffer size in chars
     *
     * @param int $numChars
     */
    public final function setBufferSize(int $numChars)
    {
        $this->ZZ_BUFFERSIZE = $numChars;
        $this->zzBuffer = array_slice($this->zzBuffer, 0, min(count($this->zzBuffer), $this->ZZ_BUFFERSIZE));
        $this->zzBuffer = array_pad($this->zzBuffer, $this->ZZ_BUFFERSIZE, "\0");
    }

    /**
     * Creates a new scanner
     *
     * @param string|Reader $in the java.io.Reader to read input from.
     */
    public function __construct($in)
    {
        if (is_string($in)) {
            $in = new Reader($in);
        }
        $this->zzReader = $in;
        $this->zzBuffer = array_pad($this->zzBuffer, $this->ZZ_BUFFERSIZE, "\0");
        !self::$ZZ_CMAP && self::$ZZ_CMAP = self::zzUnpackCMap(self::$ZZ_CMAP_PACKED);
        !self::$ZZ_ACTION && self::$ZZ_ACTION = self::zzUnpackAction();
        !self::$ZZ_ROWMAP && self::$ZZ_ROWMAP = self::zzUnpackRowMap();
        !self::$ZZ_TRANS && self::$ZZ_TRANS = self::zzUnpackTrans();
        !self::$ZZ_ATTRIBUTE && self::$ZZ_ATTRIBUTE = self::zzUnpackAttribute();
    }


    /**
     * Unpacks the compressed character translation table.
     *
     * @param string $packed the packed character translation table
     *
     * @return array        the unpacked character translation table
     */
    private static function zzUnpackCMap(string $packed)
    {
        $packed = preg_split('//u', $packed, -1, PREG_SPLIT_NO_EMPTY);
        $map = [];
        $i = 0;  /* index in packed string  */
        $j = 0;  /* index in unpacked array */
        while ($i < 2836) {
            $count = ord($packed[$i++]);
            $value = $packed[$i++];
            do {
                $map[$j++] = $value;
            } while (--$count > 0);
        }
        return $map;
    }


    /**
     * Refills the input buffer.
     *
     * @return bool     <code>false</code>, iff there was new input.
     *
     * @exception   java.io.IOException  if any I/O-Error occurs
     */
    private function zzRefill(): bool
    {
        /* first: make room (if you can) */
        if ($this->zzStartRead > 0) {
            $this->zzEndRead += $this->zzFinalHighSurrogate;
            $this->zzFinalHighSurrogate = 0;

            $this->zzBuffer = array_slice($this->zzBuffer, $this->zzStartRead, $this->zzEndRead - $this->zzStartRead);

            /* translate stored positions */
            $this->zzEndRead -= $this->zzStartRead;
            $this->zzCurrentPos -= $this->zzStartRead;
            $this->zzMarkedPos -= $this->zzStartRead;
            $this->zzStartRead = 0;
        }


        /* fill the buffer with new input */
        $requested = count($this->zzBuffer) - $this->zzEndRead - $this->zzFinalHighSurrogate;
        $totalRead = 0;
        while ($totalRead < $requested) {
            $numRead = $this->zzReader->read($this->zzBuffer, $this->zzEndRead + $totalRead, $requested - $totalRead);
            if ($numRead == -1) {
                break;
            }
            $totalRead += $numRead;
        }

        if ($totalRead > 0) {
            $this->zzEndRead += $totalRead;
            if ($totalRead == $requested) { /* possibly more input available */
                if (self::isHighSurrogate($this->zzBuffer[$this->zzEndRead - 1])) {
                    --$this->zzEndRead;
                    $this->zzFinalHighSurrogate = 1;
                    if ($totalRead == 1) {
                        return true;
                    }
                }
            }
            return false;
        }

        // totalRead = 0: End of stream
        return true;
    }

    /**
     * Indicates whether {@code ch} is a high- (or leading-) surrogate code unit
     * that is used for representing supplementary characters in UTF-16
     * encoding.
     *
     * @param $ch
     *            the character to test.
     *
     * @return {@code true} if {@code ch} is a high-surrogate code unit;
     *         {@code false} otherwise.
     * @see    #isLowSurrogate(char)
     * @since  1.5
     */
    public static function isHighSurrogate($ch): bool
    {
        return (self::MIN_HIGH_SURROGATE <= $ch && self::MAX_HIGH_SURROGATE >= $ch);
    }

    /**
     * Indicates whether {@code ch} is a low- (or trailing-) surrogate code unit
     * that is used for representing supplementary characters in UTF-16
     * encoding.
     *
     * @param $ch
     *            the character to test.
     *
     * @return {@code true} if {@code ch} is a low-surrogate code unit;
     *         {@code false} otherwise.
     * @see    #isHighSurrogate(char)
     * @since  1.5
     */
    public static function isLowSurrogate($ch): bool
    {
        return (self::MIN_LOW_SURROGATE <= $ch && self::MAX_LOW_SURROGATE >= $ch);
    }

    /**
     * Returns true if the given character is a high or low surrogate.
     *
     * @param $ch
     *
     * @return bool
     * @since 1.7
     */
    public static function isSurrogate($ch): bool
    {
        return $ch >= self::MIN_SURROGATE && $ch <= self::MAX_SURROGATE;
    }

    /**
     * Indicates whether the specified character pair is a valid surrogate pair.
     *
     * @param $high
     *            the high surrogate unit to test.
     * @param $low
     *            the low surrogate unit to test.
     *
     * @return bool {@code true} if {@code high} is a high-surrogate code unit and
     *         {@code low} is a low-surrogate code unit; {@code false}
     *         otherwise.
     * @see    #isHighSurrogate(char)
     * @see    #isLowSurrogate(char)
     * @since  1.5
     */
    public static function isSurrogatePair($high, $low): bool
    {
        return (self::isHighSurrogate($high) && self::isLowSurrogate($low));
    }


    /**
     * Closes the input stream.
     */
    public final function yyclose()
    {
        $this->zzAtEOF = true;            /* indicate end of file */
        $this->zzEndRead = $this->zzStartRead;  /* invalidate buffer    */

        if ($this->zzReader != null) {
            $this->zzReader->close();
        }
    }


    /**
     * Resets the scanner to read from a new input stream.
     * Does not close the old reader.
     *
     * All internal variables are reset, the old input stream
     * <b>cannot</b> be reused (internal buffer is discarded and lost).
     * Lexical state is set to <tt>ZZ_INITIAL</tt>.
     *
     * Internal scan buffer is resized down to its initial length, if it has grown.
     *
     * @param Reader $reader the new input stream
     */
    public final function yyreset(Reader $reader)
    {
        $this->zzReader = $reader;
        $this->zzAtBOL = true;
        $this->zzAtEOF = false;
        $this->zzEOFDone = false;
        $this->zzEndRead = $this->zzStartRead = 0;
        $this->zzCurrentPos = $this->zzMarkedPos = 0;
        $this->zzFinalHighSurrogate = 0;
        $this->yyline = $this->yychar = $this->yycolumn = 0;
        $this->zzLexicalState = self::$YYINITIAL;
        if (count($this->zzBuffer) > $this->ZZ_BUFFERSIZE) {
            $this->zzBuffer = [];
        } //new char[$this->ZZ_BUFFERSIZE];
    }


    /**
     * Returns the current lexical state.
     */
    public final function yystate(): int
    {
        return $this->zzLexicalState;
    }


    /**
     * Enters a new lexical state
     *
     * @param int $newState the new lexical state
     */
    public final function yybegin(int $newState)
    {
        $this->zzLexicalState = $newState;
    }


    /**
     * Returns the text matched by the current regular expression.
     */
    public final function yytext(): string
    {
        return implode('', array_slice($this->zzBuffer, $this->zzStartRead, $this->zzMarkedPos - $this->zzStartRead));
    }


    /**
     * Returns the character at position <tt>pos</tt> from the
     * matched text.
     *
     * It is equivalent to yytext().charAt(pos), but faster
     *
     * @param int $pos the position of the character to fetch.
     *                 A value from 0 to yylength()-1.
     *
     * @return the character at position pos
     */
    public final function yycharat(int $pos)
    {
        return $this->zzBuffer[$this->zzStartRead + $pos];
    }


    /**
     * Returns the length of the matched text region.
     */
    public final function yylength(): int
    {
        return $this->zzMarkedPos - $this->zzStartRead;
    }


    /**
     * Reports an error that occured while scanning.
     *
     * In a wellformed scanner (no or only correct usage of
     * yypushback(int) and a match-all fallback rule) this method
     * will only be called with things that "Can't Possibly Happen".
     * If this method is called, something is seriously wrong
     * (e.g. a JFlex bug producing a faulty scanner etc.).
     *
     * Usual syntax/scanner level error handling should be done
     * in error fallback rules.
     *
     * @param int $errorCode the code of the errormessage to display
     *
     * @throws \Exception
     */
    private function zzScanError(int $errorCode)
    {
        if (!isset(self::$ZZ_ERROR_MSG[$errorCode])) {
            $message = self::$ZZ_ERROR_MSG[self::$ZZ_UNKNOWN_ERROR];
        } else {
            $message = self::$ZZ_ERROR_MSG[$errorCode];
        }

        throw new \Exception($message);
    }


    /**
     * Pushes the specified amount of characters back into the input stream.
     *
     * They will be read again by then next call of the scanning method
     *
     * @param number  the number of characters to be read again.
     *                This number must not be greater than yylength()!
     *
     * @throws \Exception
     */
    public function yypushback(int $number)
    {
        if ($number > $this->yylength()) {
            $this->zzScanError(self::$ZZ_PUSHBACK_2BIG);
        }

        $this->zzMarkedPos -= $number;
    }

    public function incrementToken(): bool
    {
        $this->clearAttributes();
        $this->skippedPositions = 0;

        while (true) {
            $tokenType = $this->getNextToken();

            if ($tokenType == self::$YYEOF) {
                return false;
            }

            if ($this->yylength() <= 255) {
                $this->posIncAttribute = $this->skippedPositions + 1;
                $this->getText($this->termAttribute);
                $start = $this->yychar();
                $this->offsetAttribute = [$this->correctOffset($start), $this->correctOffset($start + mb_strlen($this->termAttribute))];
                $this->typeAttribute = self::$TOKEN_TYPES[$tokenType];
                return true;
            } else {
                // When we skip a too-long term, we still increment the
                // position increment
                $this->skippedPositions++;
            }
        }
    }


    /**
     * Resumes scanning until the next regular expression is matched,
     * the end of input is encountered or an I/O-Error occurs.
     *
     * @return int     the next token
     * @throws \Exception
     */
    public function getNextToken(): int
    {
        static $intlCache = [], $ordCache = [];

        $zzEndReadL = $this->zzEndRead;
        $zzBufferL = $this->zzBuffer;
        $zzCMapL = self::$ZZ_CMAP;

        $zzTransL = self::$ZZ_TRANS;
        $zzRowMapL = self::$ZZ_ROWMAP;
        $zzAttrL = self::$ZZ_ATTRIBUTE;

        while (true) {
//            error_log('First while');
            $zzInput = 0;
            $zzMarkedPosL = $this->zzMarkedPos;

            $this->yychar += $zzMarkedPosL - $this->zzStartRead;

            $zzAction = -1;

            $zzCurrentPosL = $this->zzCurrentPos = $this->zzStartRead = $zzMarkedPosL;
//            var_dump('START_READ');

            $this->zzState = self::$ZZ_LEXSTATE[$this->zzLexicalState];

            // set up zzAction for empty match case:
            $zzAttributes = isset($intlCache[$zzAttrL[$this->zzState]]) ? $intlCache[$zzAttrL[$this->zzState]] : $intlCache[$zzAttrL[$this->zzState]] = IntlChar::ord($zzAttrL[$this->zzState]);
            if (($zzAttributes & 1) == 1) {
                $zzAction = $this->zzState;
            }


            while (true) {
//                error_log('Second while');
                if ($zzCurrentPosL < $zzEndReadL) {
//                    var_dump(101);
                    $zzInput = isset($intlCache[$zzBufferL[$zzCurrentPosL]]) ? $intlCache[$zzBufferL[$zzCurrentPosL]] : $intlCache[$zzBufferL[$zzCurrentPosL]] = IntlChar::ord($zzBufferL[$zzCurrentPosL]); //$zzEndReadL);
                    $zzCurrentPosL += ($zzInput >= 0x10000 ? 2 : 1);
                } elseif ($this->zzAtEOF) {
//                    var_dump(102);
                    $zzInput = self::$YYEOF;
                    break;
                } else {
//                    var_dump(103);
                    // store back cached positions
                    $this->zzCurrentPos = $zzCurrentPosL;
                    $this->zzMarkedPos = $zzMarkedPosL;
                    $eof = $this->zzRefill();
                    // get translated positions and possibly new buffer
                    $zzCurrentPosL = $this->zzCurrentPos;
                    $zzMarkedPosL = $this->zzMarkedPos;
                    $zzBufferL = $this->zzBuffer;
                    $zzEndReadL = $this->zzEndRead;
                    if ($eof) {
                        $zzInput = self::$YYEOF;
                        break;
                    } else {
                        $zzInput = isset($intlCache[$zzBufferL[$zzCurrentPosL]]) ? $intlCache[$zzBufferL[$zzCurrentPosL]] : $intlCache[$zzBufferL[$zzCurrentPosL]] = IntlChar::ord($zzBufferL[$zzCurrentPosL]); //Character.codePointAt(zzBufferL, zzCurrentPosL, zzEndReadL);
                        $zzCurrentPosL += ($zzInput >= 0x10000 ? 2 : 1);
                    }
                }
                $zzNext = $zzTransL[$zzRowMapL[$this->zzState] + (isset($zzCMapL[$zzInput]) ? ($ordCache[$zzCMapL[$zzInput]] ?? $ordCache[$zzCMapL[$zzInput]] = ord($zzCMapL[$zzInput])) : 0)];
                if ($zzNext == -1) {
                    break;
                }
                $this->zzState = $zzNext;

                $zzAttributes = $zzAttrL[$this->zzState];
                if (($zzAttributes & 1) == 1) {
                    $zzAction = $this->zzState;
                    $zzMarkedPosL = $zzCurrentPosL;
                    if (($zzAttributes & 8) == 8) {
                        break;
                    }
                }
            }

            // store back cached position
            $this->zzMarkedPos = $zzMarkedPosL;
            switch ($zzAction < 0 ? $zzAction : self::$ZZ_ACTION[$zzAction]) {
                case 1:
                    /* Break so we don't hit fall-through warning: */
                    /* Not numeric, word, ideographic, hiragana, or SE Asian -- ignore it. */
                    break;
                case 9:
                case 10:
                case 11:
                case 12:
                case 13:
                case 14:
                case 15:
                case 16:
                    break;
                case 2:
                    return self::$WORD_TYPE;
                case 3:
                    return self::$HANGUL_TYPE;
                case 4:
                    return self::$NUMERIC_TYPE;
                case 5:
                    return self::$KATAKANA_TYPE;
                case 6:
                    return self::$IDEOGRAPHIC_TYPE;
                case 7:
                    return self::$HIRAGANA_TYPE;
                case 8:
                    return self::$SOUTH_EAST_ASIAN_TYPE;
                default:
//                    var_dump('DEF:', $zzInput, $this->zzStartRead, $this->zzCurrentPos);
                    if ($zzInput == self::$YYEOF && $this->zzStartRead == $this->zzCurrentPos) {
                        $this->zzAtEOF = true;

                        return self::$YYEOF;
                    } else {
                        $this->zzScanError(self::$ZZ_NO_MATCH);
                    }
            }
        }
    }

    public final function end()
    {
//        parent::end();
        // set final offset
        $finalOffset = $this->correctOffset($this->yychar() + $this->yylength());
        $this->offsetAttribute = [$finalOffset, $finalOffset];
        // adjust any skipped tokens
        $this->posIncAttribute = $this->posIncAttribute + $this->skippedPositions;
    }
}