<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form\Step;

use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use YouWillLikeIT\EGov\Enum\ApplicantType;
use YouWillLikeIT\EGov\Form\Field\IdentificationNumberField;

/**
 * Korak 1: Podnosilac zahtjeva (identičan za sve procedure).
 */
class ApplicantStep extends AbstractRequestStep
{
    private static string $table_name = 'EGov_ApplicantStep';

    private static array|string|null $next_steps = [LocationStep::class, DetailsStep::class];

    public function getTitle()
    {
        return 'Podnosilac';
    }

    public function getNextStep()
    {
        return $this->getProcedure()->RequiresLocation ? LocationStep::class : DetailsStep::class;
    }

    public function getFields(): FieldList
    {
        return FieldList::create(
            HeaderField::create('ApplicantHead', 'Podaci o podnosiocu zahtjeva', 2),
            OptionsetField::create('ApplicantType', 'Zahtjev podnosi', ApplicantType::options(), ApplicantType::Individual->value)
                ->setAttribute('data-egov-applicant-type', 'true'),
            TextField::create('ApplicantFullName', 'Ime i prezime / naziv pravnog lica')
                ->setAttribute('autocomplete', 'name'),
            TextField::create('ApplicantParentName', 'Ime jednog roditelja')
                ->setAttribute('data-show-if', 'ApplicantType=Individual')
                ->setDescription('Obavezno za fizička lica.'),
            IdentificationNumberField::create('ApplicantIdentificationNumber', 'JMBG / Identifikacioni broj')
                ->setTypeFieldName('ApplicantType')
                ->setDescription('Fizička lica: JMBG (13 cifara). Pravna lica i obrti: ID broj (13 cifara).'),
            TextField::create('RepresentativeName', 'Zastupnik / kontakt osoba (opcionalno)')
                ->setDescription('Ako zahtjev podnosi punomoćnik, punomoć priložite u koraku „Prilozi“.'),
            TextField::create('ApplicantAddress', 'Adresa (ulica i broj)')->setAttribute('autocomplete', 'street-address'),
            TextField::create('ApplicantPostalCode', 'Poštanski broj')->setAttribute('autocomplete', 'postal-code')->setMaxLength(10),
            TextField::create('ApplicantCity', 'Mjesto')->setAttribute('autocomplete', 'address-level2'),
            TextField::create('ApplicantPhone', 'Kontakt telefon')->setAttribute('autocomplete', 'tel')->setAttribute('type', 'tel'),
            EmailField::create('ApplicantEmail', 'Email adresa')
                ->setAttribute('autocomplete', 'email')
                ->setDescription('Na ovu adresu stiže link za potvrdu zahtjeva i sva obavještenja o statusu.'),
            EmailField::create('ApplicantEmailRepeat', 'Ponovite email adresu')->setAttribute('autocomplete', 'off'),
        );
    }

    public function getValidator()
    {
        return RequiredFieldsValidator::create([
            'ApplicantType',
            'ApplicantFullName',
            'ApplicantIdentificationNumber',
            'ApplicantAddress',
            'ApplicantCity',
            'ApplicantPhone',
            'ApplicantEmail',
            'ApplicantEmailRepeat',
        ]);
    }

    public function validateStep(array $data, Form $form): bool
    {
        $email = strtolower(trim((string) ($data['ApplicantEmail'] ?? '')));
        $repeat = strtolower(trim((string) ($data['ApplicantEmailRepeat'] ?? '')));
        if ($email !== $repeat) {
            $form->sessionMessage('Email adrese se ne poklapaju.', 'error');
            return false;
        }
        if (($data['ApplicantType'] ?? '') === ApplicantType::Individual->value && trim((string) ($data['ApplicantParentName'] ?? '')) === '') {
            $form->sessionMessage('Za fizička lica unesite ime jednog roditelja.', 'error');
            return false;
        }
        return true;
    }
}
