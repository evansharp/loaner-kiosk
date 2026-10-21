<?php

class PersonName
{
    /**
     * Cleans a person's name before it is saved: strips any HTML/PHP tags and trims whitespace.
     * (Output is still escaped with htmlspecialchars() wherever names are displayed.)
     */
    public static function clean(string $input): string
    {
        return trim(strip_tags($input));
    }
}
