<?php

/**
 * @file
 *          This file is part of the PdfParser library.
 *
 * @author  Sébastien MALOT <sebastien@malot.fr>
 * @date    2013-08-08
 * @license GPL-2.0
 * @url     <https://github.com/smalot/pdfparser>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Smalot\PdfParser;

use Smalot\PdfParser\Element\ElementMissing;

/**
 * Class Font
 *
 * @package Smalot\PdfParser
 */
class Font extends Object
{
    /**
     * @var Object
     */
    protected $toUnicode = false;

    /**
     * @var array
     */
    protected $table = null;

    /**
     * @var mixed
     */
    protected $encoding = null;

    /**
     * @var bool
     */
    protected $init_done = false;

    /**
     *
     */
    protected function init()
    {
        if (!$this->init_done) {
            // Load encoding informations.
            $encoding = $this->get('Encoding');

            $this->encoding = $encoding;

            // Load translate table.
            $this->loadTranslateTable();

            $this->init_done = true;
        }
    }

    /**
     * @return null|Object
     */
    public function getToUnicode()
    {
        if ($this->toUnicode !== false) {
            return $this->toUnicode;
        }

        $toUnicode = $this->getHeader()->get('ToUnicode');

        return ($this->toUnicode = $toUnicode);
    }

    /**
     * @param string $char
     *
     * @return string
     */
    public function translateChar($char, $is_hexa = false)
    {
        $this->init();

        if ($is_hexa) {
            $char = str_pad($char, 4, '0', STR_PAD_RIGHT);
            $dec  = hexdec($char);
        } else {
            $dec = hexdec(bin2hex($char));
        }

        if (array_key_exists($dec, $this->table['chars'])) {
            return $this->table['chars'][$dec];
        } else {
            return $char;
        }
    }

    /**
     * @param int $code
     *
     * @return string
     */
    protected function uchr($code)
    {
        return html_entity_decode('&#' . ((int)$code) . ';', ENT_NOQUOTES, 'UTF-8');
    }

    /**
     *
     */
    public function loadTranslateTable()
    {
        if (!is_null($this->table)) {
            return;
        }

        $this->table = array(
            'chars'  => array(),
            'ranges' => array(),
        );

        if (!$this->getToUnicode() instanceof ElementMissing) {
            $content = $this->getToUnicode()->getContent();

            if (strpos($content, 'beginbfchar') !== false) {
                $bfchar  = substr(
                    $content,
                    strpos($content, 'beginbfchar') + strlen('beginbfchar'),
                    strpos($content, 'endbfchar') - strpos($content, 'beginbfchar') - strlen('beginbfchar')
                );
                $regexp  = '/<(?<from>[0-9A-Z]+)> +<(?<to>[0-9A-Z]+)>[ \r\n]+/ims';
                $matches = array();
                preg_match_all($regexp, $bfchar, $matches);
                foreach ($matches['from'] as $key => $from) {
                    $to = $matches['to'][$key];
                    $to = $this->uchr(hexdec($to));
                    $this->table['chars'][hexdec($from)] = $to;
                }
            }

            if (strpos($content, 'beginbfrange') !== false) {
                $bfrange = substr(
                    $content,
                    strpos($content, 'beginbfrange') + strlen('beginbfrange'),
                    strpos($content, 'endbfrange') - strpos($content, 'beginbfrange') - strlen('beginbfrange')
                );
                $regexp  = '/<(?<from>[0-9A-Z]+)> *<(?<to>[0-9A-Z]+)> *<(?<offset>[0-9A-Z]+)>[ \r\n]+/ims';
                $matches = array();
                preg_match_all($regexp, $bfrange, $matches);
                foreach ($matches['from'] as $key => $from) {
                    $char_from = hexdec($from);
                    $char_to   = hexdec($matches['to'][$key]);
                    $offset    = hexdec($matches['offset'][$key]);

                    $this->table['ranges'][] = array(
                        'from'   => $char_from,
                        'to'     => $char_to,
                        'offset' => $offset,
                    );

                    for ($char = $char_from; $char <= $char_to; $char++) {
                        $to = $this->uchr($char - $char_from + $offset);

                        $this->table['chars'][$char] = $to;
                    }
                }
            }
        }
    }

    /**
     * @param string $hexa
     *
     * @return string
     */
    public function decodeHexadecimal($hexa)
    {
        $text = '';

        $matches = array();
        $regexp  = '/<(?<data>[a-z0-9]+)>\s*(?<position>[\-0-9\.]*)/mis';
        preg_match_all($regexp, $hexa, $matches);

        foreach ($matches['data'] as $pos => $hexa) {
            for ($i = 0; $i < strlen($hexa); $i = $i + 4) {
                $text .= $this->translateChar(substr($hexa, $i, 4), true);
            }

            if ((int)$matches['position'][$pos] < 0) {
                $text .= ' ';
            }
        }

        return $text;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    public function decodeOctal($text)
    {
        $found_octal_values = array();
        preg_match_all('/\\\\([0-9]{3})/', $text, $found_octal_values);
        foreach ($found_octal_values[0] as $value) {
            $octal = substr($value, 1);
            $text  = str_replace($value, chr(octdec($octal)), $text);
        }

        return $text;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    public function decodeText($text)
    {
        $this->init();

        $text = $this->decodeOctal($text);

        $result        = '';
        $cur_start_pos = 0;

        while (($cur_start_text = mb_strpos($text, '(', $cur_start_pos)) !== false) {
            // New text element found
            if ($cur_start_text - $cur_start_pos > 8) {
                $spacing = '';
            } else {
                $spacing_size = floatval(trim(mb_substr($text, $cur_start_pos, $cur_start_text - $cur_start_pos)));

                // TODO : use matrix to determine spacing
                if ($spacing_size < -50) {
                    $spacing = ' ';
                } else {
                    $spacing = '';
                }
            }
            $cur_start_text++;

            $start_search_end = $cur_start_text;
            while (($cur_start_pos = mb_strpos($text, ')', $start_search_end)) !== false) {
                $cur_extract = mb_substr($text, $cur_start_text, $cur_start_pos - $cur_start_text);
                preg_match('/(?<escape>[\\\]*)$/s', $cur_extract, $match);
                if (!(mb_strlen($match['escape']) % 2)) {
                    break;
                }
                $start_search_end = $cur_start_pos + 1;
            }

            // something wrong happened
            if ($cur_start_pos === false) {
                break;
            }

            // extract content
            $sub_text = mb_substr($text, $cur_start_text, $cur_start_pos - $cur_start_text);
//            var_dump('avant', $sub_text);
            $sub_text = str_replace(
                array('\\\\', '\(', '\)', '\n', '\r'),
                array('\\', '(', ')', "\n", "\r"),
                $sub_text
            );

            // decode content
//                var_dump('decode');
            $sub_text = $this->decodeContent($sub_text);
//            var_dump('apres', $sub_text);
//            echo '--------------' . "\n";
//            var_dump('ajout espace', $spacing, $spacing_size);

            // add content to result string
            $result .= $spacing . $sub_text;
            $cur_start_pos++;
        }

        return $result;
    }

    protected function decodeContent($text)
    {
        $this->init();

        if ($this->encoding instanceof Encoding) {

            $chars  = preg_split('//', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $result = '';

            foreach ($chars as $char) {
                $dec_av = hexdec(bin2hex($char));
                $dec_ap = $this->encoding->translateChar($dec_av);

                $result .= $this->uchr($dec_ap);
            }

            return $result;
        }

        if (!$this->getToUnicode() instanceof ElementMissing) {
//            var_dump('font->translateChar');
//            die();

            $chars  = preg_split('//', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $result = '';

            foreach ($chars as $char) {
                $result .= $this->translateChar($char);
            }

            return $result;
        }


        return $text;
    }
}