<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form\Step;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use YouWillLikeIT\EGov\Model\CadastralMunicipality;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * Korak 2: Lokacija (samo za procedure sa RequiresLocation).
 */
class LocationStep extends AbstractRequestStep
{
    private static string $table_name = 'EGov_LocationStep';

    private static array|string|null $next_steps = DetailsStep::class;

    public function getTitle()
    {
        return 'Lokacija';
    }

    public function getFields(): FieldList
    {
        $tenant = TenantConfig::singleton();
        return FieldList::create(
            HeaderField::create('LocationHead', 'Lokacija / nekretnina', 2),
            TextField::create('LocationPlace', 'Mjesto / naselje'),
            TextField::create('LocationStreet', 'Ulica i broj (ako postoji)'),
            LiteralField::create('KoHint', '<p class="egov-hint">Unesite podatke iz posjedovnog lista / kopije katastarskog plana. '
                . 'Više parcela odvojite zarezom. Dovoljno je popuniti novi <em>ili</em> stari premjer.</p>'),
            TextField::create('CadastralParcelNew', 'Broj katastarske čestice (novi premjer)'),
            DropdownField::create('CadastralMunicipalityNewID', 'Katastarska općina (novi premjer)', $tenant->cadastralMunicipalities(CadastralMunicipality::SURVEY_NEW)->map('ID', 'Title'))
                ->setEmptyString('— odaberite k.o. —'),
            TextField::create('CadastralParcelOld', 'Broj katastarske čestice (stari premjer)'),
            DropdownField::create('CadastralMunicipalityOldID', 'Katastarska općina (stari premjer)', $tenant->cadastralMunicipalities(CadastralMunicipality::SURVEY_OLD)->map('ID', 'Title'))
                ->setEmptyString('— odaberite k.o. —'),
        );
    }

    public function getValidator()
    {
        return RequiredFieldsValidator::create(['LocationPlace']);
    }

    public function validateStep(array $data, Form $form): bool
    {
        $newOk = trim((string) ($data['CadastralParcelNew'] ?? '')) !== '' && (int) ($data['CadastralMunicipalityNewID'] ?? 0) > 0;
        $oldOk = trim((string) ($data['CadastralParcelOld'] ?? '')) !== '' && (int) ($data['CadastralMunicipalityOldID'] ?? 0) > 0;
        if (!$newOk && !$oldOk) {
            $form->sessionMessage('Unesite broj katastarske čestice i katastarsku općinu (novi ili stari premjer).', 'error');
            return false;
        }
        return true;
    }
}
