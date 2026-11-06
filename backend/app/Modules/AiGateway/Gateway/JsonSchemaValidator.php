<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

/**
 * Small JSON Schema validator for model output (the subset providers accept
 * for structured output: type, properties, required, additionalProperties,
 * items, enum, minItems/maxItems, minLength/maxLength, minimum/maximum).
 * Provider-side strict mode is not trusted blindly — output is re-validated.
 */
final class JsonSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> errors (empty = valid)
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path}: value not in enum"];
        }

        $types = isset($schema['type']) ? (array) $schema['type'] : [];
        if ($types !== [] && ! $this->matchesAnyType($value, $types)) {
            return ["{$path}: expected ".implode('|', $types)];
        }

        if (is_array($value) && array_is_list($value) && ($types === [] || in_array('array', $types, true))) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $errors[] = "{$path}: too few items";
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = "{$path}: too many items";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        } elseif (is_array($value) && (in_array('object', $types, true) || isset($schema['properties']))) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists((string) $required, $value)) {
                    $errors[] = "{$path}.{$required}: required";
                }
            }
            foreach ($value as $name => $item) {
                if (isset($properties[$name]) && is_array($properties[$name])) {
                    array_push($errors, ...$this->validate($item, $properties[$name], "{$path}.{$name}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$name}: additional property not allowed";
                }
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $errors[] = "{$path}: string too short";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $errors[] = "{$path}: string too long";
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: below minimum";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: above maximum";
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
