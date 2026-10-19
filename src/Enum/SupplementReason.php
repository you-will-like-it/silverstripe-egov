<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Enum;

/**
 * Closed list of reasons for a supplement request item.
 * A referent can only request documents from the procedure catalogue and
 * only with one of these reasons ("anti-šikaniranje").
 */
enum SupplementReason: string
{
    /** Dokument nije priložen (dozvoljeno samo ako zaista nema priloga te vrste). */
    case Missing = 'Missing';
    case Illegible = 'Illegible';
    case WrongDocument = 'WrongDocument';
    case Expired = 'Expired';
    /** Samo za dokaz o uplati. */
    case WrongPayment = 'WrongPayment';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Nije priložen',
            self::Illegible => 'Nečitljiv',
            self::WrongDocument => 'Priložen pogrešan dokument',
            self::Expired => 'Istekao rok važenja',
            self::WrongPayment => 'Uplata nije izvršena prema propisanim podacima',
        };
    }

    /**
     * Reasons a referent may choose for a given document.
     *
     * @return array<string, string>
     */
    public static function optionsFor(bool $hasAttachment, bool $isPaymentProof): array
    {
        if (!$hasAttachment) {
            return [self::Missing->value => self::Missing->label()];
        }
        $out = [
            self::Illegible->value => self::Illegible->label(),
            self::WrongDocument->value => self::WrongDocument->label(),
            self::Expired->value => self::Expired->label(),
        ];
        if ($isPaymentProof) {
            $out[self::WrongPayment->value] = self::WrongPayment->label();
        }
        return $out;
    }
}
