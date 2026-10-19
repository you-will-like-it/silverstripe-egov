<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Tenant;

use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\SiteConfig\SiteConfig;
use YouWillLikeIT\EGov\Model\CadastralMunicipality;
use YouWillLikeIT\EGov\Model\PaymentAccount;

/**
 * Single read-access point for municipality ("tenant") parameters.
 * "Jedna instalacija – jedna općina": SiteConfig wins, YAML is the fallback,
 * so a new municipality is onboarded by YAML preset + CMS, without code changes.
 */
class TenantConfig
{
    use Injectable;
    use Configurable;

    /**
     * @config
     * @var array<string, string>
     */
    private static array $defaults = [
        'municipality_type' => 'Municipality',
        'municipality_name' => '',
        'municipality_code' => '',
        'municipality_id_number' => '',
        'canton' => 'Tuzlanski kanton',
        'entity' => 'Federacija Bosne i Hercegovine',
        'country' => 'Bosna i Hercegovina',
        'tracking_prefix' => 'EGOV',
        'address' => '',
        'phone' => '',
        'email' => '',
        'from_email' => '',
        'pdf_footer' => '',
        'privacy_notice' => 'Lični podaci navedeni u zahtjevu obrađuju se isključivo u svrhu vođenja upravnog postupka, '
            . 'u skladu sa važećim propisima o zaštiti ličnih podataka i o upravnom postupku.',
    ];

    private ?SiteConfig $siteConfig = null;

    private function site(): SiteConfig
    {
        return $this->siteConfig ??= SiteConfig::current_site_config();
    }

    private function value(string $siteField, string $yamlKey): string
    {
        $value = trim((string) $this->site()->getField($siteField));
        if ($value !== '') {
            return $value;
        }
        $defaults = (array) static::config()->get('defaults');
        return trim((string) ($defaults[$yamlKey] ?? ''));
    }

    public function isCity(): bool
    {
        return $this->value('EGovMunicipalityType', 'municipality_type') === 'City';
    }

    public function typeLabel(): string
    {
        return $this->isCity() ? 'Grad' : 'Općina';
    }

    public function name(): string
    {
        return $this->value('EGovMunicipalityName', 'municipality_name');
    }

    /** "Grad Srebrenik" / "Općina Banovići" */
    public function fullName(): string
    {
        return trim($this->typeLabel() . ' ' . $this->name());
    }

    /** Uppercase, used in PDF headers: "GRAD SREBRENIK" */
    public function fullNameUpper(): string
    {
        return mb_strtoupper($this->fullName());
    }

    /** Locative used in sentences: "U Srebreniku" is not derivable – configurable via YAML 'place_locative'. */
    public function placeLocative(): string
    {
        $defaults = (array) static::config()->get('defaults');
        return (string) ($defaults['place_locative'] ?? $this->name());
    }

    public function municipalityCode(): string
    {
        return $this->value('EGovMunicipalityCode', 'municipality_code');
    }

    public function idNumber(): string
    {
        return $this->value('EGovMunicipalityIdNumber', 'municipality_id_number');
    }

    public function canton(): string
    {
        return $this->value('EGovCanton', 'canton');
    }

    public function entity(): string
    {
        return $this->value('EGovEntity', 'entity');
    }

    public function country(): string
    {
        $defaults = (array) static::config()->get('defaults');
        return (string) ($defaults['country'] ?? 'Bosna i Hercegovina');
    }

    public function trackingPrefix(): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $this->value('EGovTrackingPrefix', 'tracking_prefix')));
        return $prefix !== '' ? substr($prefix, 0, 5) : 'EGOV';
    }

    public function address(): string
    {
        return $this->value('EGovAddress', 'address');
    }

    public function phone(): string
    {
        return $this->value('EGovPhone', 'phone');
    }

    public function email(): string
    {
        return $this->value('EGovEmail', 'email');
    }

    public function fromEmail(): string
    {
        $from = $this->value('EGovFromEmail', 'from_email');
        return $from !== '' ? $from : $this->email();
    }

    public function pdfFooter(): string
    {
        return $this->value('EGovPdfFooter', 'pdf_footer');
    }

    public function privacyNotice(): string
    {
        return $this->value('EGovPrivacyNotice', 'privacy_notice');
    }

    public function logo(): ?Image
    {
        $logo = $this->site()->getComponent('EGovLogo');
        return $logo instanceof Image && $logo->exists() ? $logo : null;
    }

    /**
     * Header lines of official documents (Memorandum).
     *
     * @return list<string>
     */
    public function letterheadLines(): array
    {
        return array_values(array_filter([
            mb_strtoupper($this->country()),
            mb_strtoupper($this->entity()),
            mb_strtoupper($this->canton()),
            $this->fullNameUpper(),
        ]));
    }

    /**
     * @return DataList<PaymentAccount>
     */
    public function paymentAccounts(): DataList
    {
        return PaymentAccount::get()->filter('IsActive', true);
    }

    public function isKnownAccount(string $account): bool
    {
        $normalized = PaymentAccount::normalize($account);
        return $normalized !== '' && $this->paymentAccounts()->filter('AccountNumber', $normalized)->exists();
    }

    /**
     * @return DataList<CadastralMunicipality>
     */
    public function cadastralMunicipalities(?string $survey = null): DataList
    {
        $list = CadastralMunicipality::get()->filter('IsActive', true);
        return $survey === null ? $list : $list->filter('Survey', $survey);
    }

    /**
     * Template-friendly flat array (emails, PDF, portal).
     *
     * @return array<string, mixed>
     */
    public function toTemplateData(): array
    {
        return [
            'TenantFullName' => $this->fullName(),
            'TenantFullNameUpper' => $this->fullNameUpper(),
            'TenantName' => $this->name(),
            'TenantTypeLabel' => $this->typeLabel(),
            'TenantMunicipalityCode' => $this->municipalityCode(),
            'TenantAddress' => $this->address(),
            'TenantPhone' => $this->phone(),
            'TenantEmail' => $this->email(),
            'TenantLogo' => $this->logo(),
            'TenantPlaceLocative' => $this->placeLocative(),
            'TenantPdfFooter' => $this->pdfFooter(),
            'TenantPrivacyNotice' => $this->privacyNotice(),
        ];
    }
}
