<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Procedure;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;

/**
 * Turns the YAML payload schema into form fields, extracts sanitised values
 * from submitted data and produces human readable label/value pairs.
 *
 * Supported field spec keys: type (text|textarea|number|date|email|dropdown|checkbox),
 * label, required, description, options (dropdown), max_length.
 */
class PayloadFieldFactory
{
    use Injectable;

    public const PREFIX = 'Payload_';

    /**
     * @param array<string, array<string, mixed>> $schema
     */
    public function buildFields(array $schema): FieldList
    {
        $fields = FieldList::create();
        foreach ($schema as $name => $spec) {
            $fields->push($this->buildField($name, $spec));
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function buildField(string $name, array $spec): FormField
    {
        $fieldName = self::PREFIX . $name;
        $label = (string) ($spec['label'] ?? $name);
        $field = match ((string) ($spec['type'] ?? 'text')) {
            'textarea' => TextareaField::create($fieldName, $label)->setRows(5),
            'number' => NumericField::create($fieldName, $label)->setScale((int) ($spec['scale'] ?? 0)),
            'date' => DateField::create($fieldName, $label),
            'email' => EmailField::create($fieldName, $label),
            'dropdown' => DropdownField::create($fieldName, $label, $this->options($spec))->setEmptyString('— odaberite —'),
            'checkbox' => CheckboxField::create($fieldName, $label),
            default => TextField::create($fieldName, $label),
        };
        if (!empty($spec['description'])) {
            $field->setDescription((string) $spec['description']);
        }
        if (!empty($spec['max_length']) && $field instanceof TextField) {
            $field->setMaxLength((int) $spec['max_length']);
        }
        return $field;
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @return list<string>
     */
    public function requiredNames(array $schema): array
    {
        $names = [];
        foreach ($schema as $name => $spec) {
            if (!empty($spec['required']) && ($spec['type'] ?? 'text') !== 'checkbox') {
                $names[] = self::PREFIX . $name;
            }
        }
        return $names;
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @param array<string, mixed> $data
     * @return array<string, scalar|null>
     */
    public function extractValues(array $schema, array $data): array
    {
        $values = [];
        foreach ($schema as $name => $spec) {
            $key = self::PREFIX . $name;
            $type = (string) ($spec['type'] ?? 'text');
            $raw = $data[$key] ?? null;
            $values[$name] = match ($type) {
                'checkbox' => !empty($raw),
                'number' => is_numeric($raw) ? (float) $raw : null,
                'dropdown' => array_key_exists((string) $raw, $this->options($spec)) ? (string) $raw : null,
                default => $raw === null ? null : mb_substr(trim((string) $raw), 0, (int) ($spec['max_length'] ?? 5000)),
            };
        }
        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @param array<string, mixed> $payload
     * @return array<string, string> label => display value
     */
    public function describe(array $schema, array $payload): array
    {
        $out = [];
        foreach ($schema as $name => $spec) {
            $value = $payload[$name] ?? null;
            $label = (string) ($spec['label'] ?? $name);
            $out[$label] = match ((string) ($spec['type'] ?? 'text')) {
                'checkbox' => $value ? 'Da' : 'Ne',
                'dropdown' => $this->options($spec)[(string) $value] ?? '—',
                'date' => $value ? date('d.m.Y.', (int) strtotime((string) $value)) : '—',
                'number' => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ','),
                default => ($value === null || $value === '') ? '—' : (string) $value,
            };
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, string>
     */
    private function options(array $spec): array
    {
        $options = $spec['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $key => $label) {
            $out[(string) $key] = (string) $label;
        }
        return $out;
    }
}
