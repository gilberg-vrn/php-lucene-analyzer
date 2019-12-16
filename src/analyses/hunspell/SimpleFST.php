<?php

namespace ftIndex\analyses\hunspell;

/**
 * Class SimpleFST
 *
 * @package ftIndex\analyses\hunspell
 * @author  Dmitrii Emelyanov <gilberg.vrn@gmail.com>
 * @date    9/20/19 12:47 PM
 */
class SimpleFST
{
    /** @var FSTNode[]  */
    protected $nodes = [];

    public function __construct()
    {
        $this->nodes = [
            0 =>new FSTNode(null)
        ];
    }

    public function addWord($word, $options)
    {
        $node = $this->nodes[0];
        $length = mb_strlen($word);
        for ($i = 0; $i < $length; $i++) {
            $label = mb_substr($word, $i, 1);
            $foundNextNode = false;
            foreach ($node->getVariants() as $variant) {
                if ($label === $this->nodes[$variant]->getLabel()) {
                    $node = $this->nodes[$variant];
                    $foundNextNode = true;
                    break;
                }
            }

            if ($foundNextNode) {
                continue;
            }

            $newNode = new FSTNode($label);
            $address = count($this->nodes);
            $this->nodes[] = $newNode;
            $node->addVariant($address);
            $node = $newNode;
        }
        $node->setFinal(true);
        $node->setOptions($options);
    }

    /**
     * @param array|string $word
     *
     * @return array|null
     */
    public function lookupWord($word)
    {
        $node = $this->nodes[0];
        if (is_array($word)) {
            $length = count($word);
        } else {
            $length = mb_strlen($word);
        }
        for ($i = 0; $i < $length; $i++) {
            if (is_array($word)) {
                $label = $word[$i];
            } else {
                $label = mb_substr($word, $i, 1);
            }
            $foundNextNode = false;
            foreach ($node->getVariants() as $variant) {
                if ($label === $this->nodes[$variant]->getLabel()) {
                    $node = $this->nodes[$variant];
                    $foundNextNode = true;
                    break;
                }
            }

            if ($foundNextNode) {
                continue;
            } else {
                return null;
            }
        }
        if ($node->isFinal()) {
            return $node->getOptions();
        }

        return null;
    }

    /**
     * @return FSTNode[]
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

}

class FSTNode
{

    protected $label;

    protected $final;

    protected $variants = [];

    protected $options = [];

    public function __construct($label)
    {
        $this->label = $label;
    }

    /**
     * @param mixed $final
     */
    public function setFinal($final): void
    {
        $this->final = $final;
    }

    /**
     * @param int $variant
     */
    public function addVariant($variant): void
    {
        $this->variants[] = $variant;
    }

    /**
     * @return array
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * @return mixed
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * @return mixed
     */
    public function isFinal()
    {
        return $this->final;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}