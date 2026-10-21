<?php

/**
 * Base for a group of admin settings stored in the settings table.
 * Each public property listed in KEYS is loaded/saved under its settings key,
 * cast back to the type of the property's default value (bool, int or string).
 */
abstract class SettingsGroup
{
    /** @var array<string, string> Property name => settings table key; defined by each subclass */
    const KEYS = [];

    /**
     * @return static
     */
    public static function load(SettingsRepository $repository)
    {
        $settings = new static();
        $stored = $repository->all();

        foreach (static::KEYS as $property => $key) {
            if (!isset($stored[$key])) {
                continue;
            }
            $default = $settings->$property;
            $value = $stored[$key];
            if (is_bool($default)) {
                $value = $value === '1';
            } elseif (is_int($default)) {
                $value = (int)$value;
            }
            $settings->$property = $value;
        }

        return $settings;
    }

    public function save(SettingsRepository $repository): void
    {
        $values = [];
        foreach (static::KEYS as $property => $key) {
            $value = $this->$property;
            $values[$key] = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        }
        $repository->setMany($values);
    }

    /**
     * Reads a single-line text field from submitted form values.
     * Line breaks are removed: they could inject extra headers into emails or API queries.
     */
    protected static function postedLine(array $post, string $name): string
    {
        return trim(str_replace(["\r", "\n"], '', (string)($post[$name] ?? '')));
    }
}
