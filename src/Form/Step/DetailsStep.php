<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form\Step;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;

/**
 * Korak 3: Podaci specifični za proceduru – delegated to the request class
 * (typed subclass fields and/or YAML FormPayload schema).
 */
class DetailsStep extends AbstractRequestStep
{
    private static string $table_name = 'EGov_DetailsStep';

    private static array|string|null $next_steps = ReviewStep::class;

    public function getTitle()
    {
        return 'Podaci o zahtjevu';
    }

    public function getFields(): FieldList
    {
        $fields = FieldList::create(
            HeaderField::create('DetailsHead', $this->getProcedure()->Title, 2)
        );
        $procedureFields = $this->getRequestPrototype()->getProcedureFormFields();
        if ($procedureFields->count() === 0) {
            $fields->push(LiteralField::create('NoDetails', '<p class="egov-hint">Za ovaj zahtjev nisu potrebni dodatni podaci.</p>'));
        }
        foreach ($procedureFields as $field) {
            $fields->push($field);
        }
        return $fields;
    }

    public function getValidator()
    {
        $required = $this->getRequestPrototype()->getProcedureRequiredFields();
        return $required === [] ? null : RequiredFieldsValidator::create($required);
    }
}
