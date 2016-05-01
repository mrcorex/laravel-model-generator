<?php

namespace CoRex\Generator\Helpers;

class Convention
{
    /**
     * Return input string as camel case.
     *
     * @param string $string
     * @return string
     */
    public static function getCamelCase($string)
    {
        $pascalCase = self::getPascalCase($string);
        $snakeCase = self::getSnakeCase($string);

        if ($pascalCase === $string && $snakeCase === $pascalCase) {
            return $string;
        }

        return lcfirst($pascalCase);
    }

    /**
     * Return input string as pascal case.
     *
     * @param string $string
     * @return string
     */
    public static function getPascalCase($string)
    {
        $lowerCase = strtolower(trim($string));
        $snakeCase = self::getSnakeCase($string);

        $replace = preg_replace_callback('/(^|_)([a-z])/', function ($match) {
            return strtoupper($match[2]);
        }, $snakeCase);

        if ($lowerCase === $replace) {
            return $string;
        }

        return $replace;
    }

    /**
     * Return input string as snake case.
     *
     * @param string $string
     * @return string
     */
    public static function getSnakeCase($string)
    {
        $lower = strtolower(trim($string));
        $replace = strtolower(preg_replace(
            ['/\s+/', '/\s/', '/(?|([a-z\d])([A-Z])|([^\^])([A-Z][a-z]))/'],
            [' ', '_', '$1_$2'],
            trim($string)
        ));

        if ($lower === $replace) {
            return $string;
        }

        return $replace;
    }
}