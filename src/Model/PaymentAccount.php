<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Transakcijski (žiro) račun općine za uplatu taksi, naknada i renti.
 *
 * @property string $Title
 * @property string $AccountNumber  16 digits, stored without spaces
 * @property string $BankName
 * @property string $Recipient
 * @property bool $IsActive
 * @property int $SiteConfigID
 */
class PaymentAccount extends DataObject
{
    private static string $table_name = 'EGov_PaymentAccount';

    private static string $singular_name = 'Račun za uplatu';

    private static string $plural_name = 'Računi za uplatu';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'AccountNumber' => 'Varchar(16)',
        'BankName' => 'Varchar(255)',
        'Recipient' => 'Varchar(255)',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $has_one = [
        'SiteConfig' => SiteConfig::class,
    ];

    private static array $summary_fields = [
        'Title' => 'Namjena',
        'FormattedAccountNumber' => 'Račun',
        'BankName' => 'Banka',
        'IsActive.Nice' => 'Aktivan',
    ];

    private static array $casting = [
        'FormattedAccountNumber' => 'Varchar',
    ];

    public static function normalize(string $account): string
    {
        return (string) preg_replace('/\D+/', '', $account);
    }

    /**
     * Human format used on BiH payment slips: 132 150 0299 280 097 (3-3-4-3-3).
     */
    public static function format(string $account): string
    {
        $digits = self::normalize($account);
        if (strlen($digits) !== 16) {
            return $digits;
        }
        return implode(' ', [
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 4),
            substr($digits, 10, 3),
            substr($digits, 13, 3),
        ]);
    }

    public function getFormattedAccountNumber(): string
    {
        return self::format((string) $this->AccountNumber);
    }

    public function getTitleWithAccount(): string
    {
        return sprintf('%s (%s)', $this->Title, $this->getFormattedAccountNumber());
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->AccountNumber = self::normalize((string) $this->AccountNumber);
        if (!$this->SiteConfigID) {
            $this->SiteConfigID = (int) SiteConfig::current_site_config()->ID;
        }
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (strlen(self::normalize((string) $this->AccountNumber)) !== 16) {
            $result->addFieldError(
                'AccountNumber',
                _t(self::class . '.ACCOUNT_LENGTH', 'Transakcijski račun mora imati tačno 16 cifara.')
            );
        }
        return $result;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(self::class . '.Title', 'Namjena računa'),
            'AccountNumber' => _t(self::class . '.AccountNumber', 'Transakcijski račun (16 cifara)'),
            'BankName' => _t(self::class . '.BankName', 'Banka'),
            'Recipient' => _t(self::class . '.Recipient', 'Primalac'),
            'IsActive' => _t(self::class . '.IsActive', 'Aktivan'),
        ]);
    }

    public function canView($member = null): bool
    {
        return Permission::checkMember($member, ['EGOV_REFERENT', 'EGOV_SUPERVISOR', 'EGOV_CONFIG']);
    }

    public function canEdit($member = null): bool
    {
        return Permission::checkMember($member, 'EGOV_CONFIG');
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::checkMember($member, 'EGOV_CONFIG');
    }

    public function canDelete($member = null): bool
    {
        return Permission::checkMember($member, 'EGOV_CONFIG')
            && !FeeItem::get()->filter('PaymentAccountID', $this->ID)->exists();
    }
}
