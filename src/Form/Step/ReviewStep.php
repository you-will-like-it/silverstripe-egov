<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form\Step;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use YouWillLikeIT\EGov\Form\RequestMultiForm;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * Korak 4: Pregled, saglasnost i izjava o tačnosti podataka.
 */
class ReviewStep extends AbstractRequestStep
{
    private static string $table_name = 'EGov_ReviewStep';

    private static bool $is_final_step = true;

    public function getTitle()
    {
        return 'Pregled i saglasnost';
    }

    public function getFields(): FieldList
    {
        $form = $this->getForm();
        $prototype = $form instanceof RequestMultiForm ? $form->buildRequestFromSteps(false) : $this->getRequestPrototype();
        $procedure = $this->getProcedure();

        $summary = (string) $prototype->customise([
            'Procedure' => $procedure,
            'Summary' => $prototype->getProcedureSummary(),
            'Payments' => $prototype->getExpectedPayments(),
            'Documents' => $procedure->citizenDocuments(),
            'ExOfficioDocuments' => $procedure->RequiredDocuments()->filter('ObtainedExOfficio', true),
            'ApplicantRows' => ArrayList::create([
                ArrayData::create(['Label' => 'Podnosilac', 'Value' => $prototype->ApplicantFullName]),
                ArrayData::create(['Label' => 'JMBG / ID', 'Value' => $prototype->getApplicantIdentificationMasked()]),
                ArrayData::create(['Label' => 'Adresa', 'Value' => trim($prototype->ApplicantAddress . ', ' . $prototype->ApplicantPostalCode . ' ' . $prototype->ApplicantCity, ' ,')]),
                ArrayData::create(['Label' => 'Telefon', 'Value' => $prototype->ApplicantPhone]),
                ArrayData::create(['Label' => 'Email', 'Value' => $prototype->ApplicantEmail]),
            ]),
            'CadastralSummary' => $prototype->getCadastralSummary(),
            'LocationLine' => trim($prototype->LocationPlace . ', ' . $prototype->LocationStreet, ' ,'),
        ])->renderWith('YouWillLikeIT\\EGov\\Includes\\ReviewSummary');

        return FieldList::create(
            HeaderField::create('ReviewHead', 'Pregled zahtjeva', 2),
            LiteralField::create('ReviewSummary', $summary),
            LiteralField::create('PrivacyNotice', sprintf(
                '<div class="egov-notice">%s</div>',
                nl2br(htmlspecialchars(TenantConfig::singleton()->privacyNotice(), ENT_QUOTES))
            )),
            CheckboxField::create('PrivacyConsent', 'Upoznat/a sam sa obavještenjem o obradi ličnih podataka.'),
            CheckboxField::create('TruthStatement', 'Izjavljujem pod materijalnom i krivičnom odgovornošću da su navedeni podaci tačni.'),
            LiteralField::create('NextHint', '<p class="egov-hint">U sljedećem koraku prilažete dokumente. '
                . 'Zahtjev se smatra podnesenim tek nakon potvrde putem linka koji ćete dobiti na email.</p>'),
        );
    }

    public function getValidator()
    {
        return RequiredFieldsValidator::create(['PrivacyConsent', 'TruthStatement']);
    }
}
