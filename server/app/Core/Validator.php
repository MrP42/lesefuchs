<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Einfacher, regelbasierter Validator.
 * Regeln je Feld als String: "required|email|min:8|max:255|in:a,b|confirmed|same:other"
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data, private array $rules) {}

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function passes(): bool
    {
        $this->errors = [];
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $this->apply($field, $value, $name, $param);
            }
        }
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,string[]> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }
        return null;
    }

    private function apply(string $field, mixed $value, string $name, ?string $param): void
    {
        switch ($name) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    $this->add($field, "Das Feld {$field} ist erforderlich.");
                }
                break;
            case 'email':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->add($field, 'Bitte eine gültige E-Mail-Adresse angeben.');
                }
                break;
            case 'min':
                if ($value !== null && mb_strlen((string) $value) < (int) $param) {
                    $this->add($field, "Mindestens {$param} Zeichen erforderlich.");
                }
                break;
            case 'max':
                if ($value !== null && mb_strlen((string) $value) > (int) $param) {
                    $this->add($field, "Höchstens {$param} Zeichen erlaubt.");
                }
                break;
            case 'in':
                $allowed = explode(',', (string) $param);
                if ($value !== null && $value !== '' && !in_array((string) $value, $allowed, true)) {
                    $this->add($field, 'Ungültiger Wert.');
                }
                break;
            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    $this->add($field, 'Die Bestätigung stimmt nicht überein.');
                }
                break;
            case 'same':
                if (($this->data[$param] ?? null) !== $value) {
                    $this->add($field, 'Die Werte stimmen nicht überein.');
                }
                break;
            case '':
                break;
            default:
                // unbekannte Regel ignorieren
                break;
        }
    }

    private function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
