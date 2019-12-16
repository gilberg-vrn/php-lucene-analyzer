<?php

namespace ftIndex\analyses\enchant;

/**
 * Class EnchantStemmer
 *
 * @package ftIndex\analyses\hunspell
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/16/19 12:33 PM
 */
class EnchantStemmer
{
    protected $loc;

    public function __construct($loc)
    {
        $this->loc = $loc;
    }

    public function uniqueStems($word, $wordLength = null)
    {
        $descSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $r = proc_open('hunspell -m -s -d ' . $this->loc, $descSpec, $pipes);
        fwrite($pipes[0], $word);
        fclose($pipes[0]);
        $buffer = trim(fgets($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($r);
//        var_dump($buffer);

        $words = explode(' ', $buffer);

        return [array_pop($words)];
    }
}