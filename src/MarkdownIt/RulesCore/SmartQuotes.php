<?php
namespace Kaoken\MarkdownIt\RulesCore;

use Kaoken\MarkdownIt\Common\Utils;

class SmartQuotes
{
    const QUOTE_TEST_RE = "/['\"]/";
    const QUOTE_RE = "/['\"]/"; // /g

    const APOSTROPHE = "’"; /* \u2019 */
    protected $apostropheLen = 1;
    
    public $utils;
    
    public function __construct()
    {
        $this->apostropheLen = strlen(self::APOSTROPHE);
        $this->utils = Utils::getInstance();
    }

    /**
     * @param string  $str
     * @param integer $index
     * @param string  $ch
     * @return string
     */
    protected function replaceAt($str, $index, $ch)
    {
        return substr($str, 0, $index) . $ch . substr($str,$index + 1);
    }

    /**
     * @param \Kaoken\MarkdownIt\Token[] $tokens
     * @param \Kaoken\MarkdownIt\RulesCore\StateCore $state
     */
    protected function process_inlines(array &$tokens, &$state)
    {
        $stack = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            $thisLevel = $tokens[$i]->level;

            for ($j = count($stack) - 1; $j >= 0; $j--) {
                if ($stack[$j]->level <= $thisLevel) { break; }
            }
            $this->utils->resizeArray($stack, $j + 1);

            if ($token->type !== 'text') { continue; }

            $text = $token->content;
            $pos = 0;
            $max = strlen($text);

            /*eslint no-labels:0,block-scoped-var:0*/
            OUTER:
            while ($pos < $max) {
                // var QUOTE_RE = /['\"]/g;
                if (!preg_match_all(self::QUOTE_RE, $text, $t, PREG_SET_ORDER|PREG_OFFSET_CAPTURE, $pos)) { break; }
                $t = $t[0];
                $canOpen = $canClose = true;
                $pos = $t[0][1] + 1;
                $isSingle = ($t[0][0] === "'");

                // Find previous character,
                // default to space if it's the beginning of the line
                //
                $lastChar = ' ';

                if ($t[0][1] - 1 >= 0) {
                    $lastChar = $text[$t[0][1] - 1];
                } else {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if ($tokens[$j]->type !== 'text') { continue; }

                        $lastChar = $tokens[$j]->content[strlen($tokens[$j]->content) - 1];
                        break;
                    }
                }

                // Find next character,
                // default to space if it's the end of the line
                //
                $nextChar = ' ';

                if ($pos < $max) {
                    $nextChar = $text[$pos];
                } else {
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if ($tokens[$j]->type !== 'text') { continue; }

                        $nextChar = $tokens[$j]->content[0];
                        break;
                    }
                }

                $isLastPunctChar = $this->utils->isMdAsciiPunct($lastChar) || $this->utils->isPunctChar($this->utils->fromCharCode($lastChar));
                $isNextPunctChar = $this->utils->isMdAsciiPunct($nextChar) || $this->utils->isPunctChar($this->utils->fromCharCode($nextChar));

                $isLastWhiteSpace = $this->utils->isWhiteSpace($lastChar);
                $isNextWhiteSpace = $this->utils->isWhiteSpace($nextChar);

                if ($isNextWhiteSpace) {
                    $canOpen = false;
                } else if ($isNextPunctChar) {
                    if (!($isLastWhiteSpace || $isLastPunctChar)) {
                        $canOpen = false;
                    }
                }

                if ($isLastWhiteSpace) {
                    $canClose = false;
                } else if ($isLastPunctChar) {
                    if (!($isNextWhiteSpace || $isNextPunctChar)) {
                        $canClose = false;
                    }
                }

                if ($nextChar === '"' && $t[0][0] === '"') {
                    if (($x=ord($lastChar)) >= 0x30 /* 0 */ && $x <= 0x39 /* 9 */) {
                        // special case: 1"" - count first quote as an inch
                        $canClose = $canOpen = false;
                    }
                }

                if ($canOpen && $canClose) {
                    // treat this as the middle of the word
                    $canOpen = false;
                    $canClose = $isNextPunctChar;
                }

                if (!$canOpen && !$canClose) {
                    // middle of word
                    if ($isSingle) {
                        $token->content = self::replaceAt($token->content, $t[0][1], self::APOSTROPHE);
                        $text = $token->content;
                        $pos += $this->apostropheLen - 1;
                        $max += $this->apostropheLen - 1;
                    }
                    continue;
                }

                if ($canClose) {
                    // this could be a closing quote, rewind the $stack to get a match
                    for ($j = count($stack) - 1; $j >= 0; $j--) {
                        $item = $stack[$j];
                        if ($stack[$j]->level < $thisLevel) { break; }
                        if ($item->single === $isSingle && $stack[$j]->level === $thisLevel) {
                            $item = $stack[$j];

                            if ($isSingle) {
                                if( is_array($state->md->options->quotes) ){
                                    $openQuote = $state->md->options->quotes[2];
                                    $closeQuote = $state->md->options->quotes[3];
                                }else{
                                    $openQuote = mb_substr($state->md->options->quotes, 2,1);
                                    $closeQuote = mb_substr($state->md->options->quotes, 3,1);
                                }
                            } else {
                                if( is_array($state->md->options->quotes) ) {
                                    $openQuote = $state->md->options->quotes[0];
                                    $closeQuote = $state->md->options->quotes[1];
                                }else{
                                    $openQuote = mb_substr($state->md->options->quotes, 0,1);
                                    $closeQuote = mb_substr($state->md->options->quotes, 1,1);
                                }
                            }

                            // replace $token->content *before* $tokens[$item->token]->content,
                            // because, if they are pointing at the same token, replaceAt
                            // could mess up indices when quote length != 1
                            $token->content = self::replaceAt($token->content, $t[0][1], $closeQuote);
                            $tokens[$item->token]->content = self::replaceAt(
                                $tokens[$item->token]->content, $item->pos, $openQuote);

                            $pos += strlen($closeQuote) - 1;
                            if ($item->token === $i) { $pos += strlen($openQuote) - 1; }

                            $text = $token->content;
                            $max = strlen($text);

                            $this->utils->resizeArray($stack,$j);
                            goto OUTER;
                        }
                    }
                }

                if ($canOpen) {
                    $o = new \stdClass();
                    $o->token   = $i;
                    $o->pos     = $t[0][1];
                    $o->single  = $isSingle;
                    $o->level   = $thisLevel;

                    $stack[] = $o;
                } else if ($canClose && $isSingle) {
                    $token->content = self::replaceAt($token->content, $t[0][1], self::APOSTROPHE);
                    $text = $token->content;
                    $pos += $this->apostropheLen - 1;
                    $max += $this->apostropheLen - 1;
                }
            }
        }
    }

    /**
     * @param StateCore $state
     */
    public function set(&$state)
    {
        /*eslint $max-depth:0*/

        if (!$state->md->options->typographer) { return; }

        for ($blkIdx = count($state->tokens) - 1; $blkIdx >= 0; $blkIdx--) {

            if ($state->tokens[$blkIdx]->type !== 'inline' ||
                !preg_match(self::QUOTE_TEST_RE, $state->tokens[$blkIdx]->content)) {
                continue;
            }

            self::process_inlines($state->tokens[$blkIdx]->children, $state);
        }
    }
}