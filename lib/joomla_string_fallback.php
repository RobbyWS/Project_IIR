<?php

namespace Joomla\String;

class StringHelper
{
    public static function strtolower(string $text): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        return strtolower($text);
    }

    public static function strlen(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

    public static function substr(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            if ($length === null) {
                return mb_substr($text, $start, mb_strlen($text, 'UTF-8'), 'UTF-8');
            }

            return mb_substr($text, $start, $length, 'UTF-8');
        }

        if ($length === null) {
            return substr($text, $start);
        }

        return substr($text, $start, $length);
    }

    public static function strpos(string $haystack, string $needle, int $offset = 0)
    {
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, $offset, 'UTF-8');
        }

        return strpos($haystack, $needle, $offset);
    }

    public static function strrpos(string $haystack, string $needle, int $offset = 0)
    {
        if (function_exists('mb_strrpos')) {
            return mb_strrpos($haystack, $needle, $offset, 'UTF-8');
        }

        return strrpos($haystack, $needle, $offset);
    }
}
