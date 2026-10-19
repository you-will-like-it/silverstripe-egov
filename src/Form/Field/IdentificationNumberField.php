<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form\Field;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\TextField;
use YouWillLikeIT\EGov\Enum\ApplicantType;
use YouWillLikeIT\EGov\Validation\IdentificationNumberValidator;

/**
 * JMBG / ID input with server-side checksum validation.
 * The applicant type is read from a sibling field in the same form.
 *
 * Uses the Silverstripe 6 FormField::validate(): ValidationResult signature.
 */
class IdentificationNumberField extends TextField
{
    private string $typeFieldName = 'ApplicantType';

    public function __construct($name, $title = null, $value = '')
    {
        parent::__construct($name, $title, $value, 13);
        $this->setAttribute('inputmode', 'numeric');
        $this->setAttribute('autocomplete', 'off');
        $this->setAttribute('pattern', '\d{13}');
        $this->setAttribute('data-egov-id-field', 'true');
    }

    public function setTypeFieldName(string $name): static
    {
        $this->typeFieldName = $name;
        $this->setAttribute('data-type-field', $name);
        return $this;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $value = (string) $this->getValue();
        if ($value === '') {
            return $result; // "required" is handled by RequiredFieldsValidator
        }

        $type = ApplicantType::Individual;
        $typeField = $this->getForm()?->Fields()->dataFieldByName($this->typeFieldName);
        if ($typeField) {
            $type = ApplicantType::tryFrom((string) $typeField->getValue()) ?? ApplicantType::Individual;
        }

        $error = Injector::inst()->get(IdentificationNumberValidator::class)->validate($value, $type);
        if ($error !== null) {
            $result->addFieldError($this->getName(), $error);
        }
        return $result;
    }
}
