<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Workflow;

use SilverStripe\Security\Member;
use YouWillLikeIT\EGov\Enum\DismissalReason;

/**
 * Input of a single status transition. No field of this object is ever sent to
 * the citizen as free text: notes are internal, supplement items and dismissal
 * reasons come from closed lists.
 */
final readonly class TransitionContext
{
    public const ACTOR_CITIZEN = 'Citizen';
    public const ACTOR_STAFF = 'Staff';
    public const ACTOR_SYSTEM = 'System';

    /**
     * @param array<int, string> $supplementItems RequiredDocumentID => SupplementReason value
     * @param array<int, float> $assessedFees FeeItemID => amount fixed by the referent (range fees only)
     */
    public function __construct(
        public string $actorType = self::ACTOR_STAFF,
        public ?Member $member = null,
        public string $internalNote = '',
        public array $supplementItems = [],
        public ?int $supplementDays = null,
        public ?DismissalReason $dismissalReason = null,
        public string $rejectionNote = '',
        public bool $notifyCitizen = true,
        public array $assessedFees = [],
    ) {
    }

    public static function citizen(string $note = ''): self
    {
        return new self(actorType: self::ACTOR_CITIZEN, internalNote: $note);
    }

    public static function system(string $note = '', ?DismissalReason $reason = null): self
    {
        return new self(actorType: self::ACTOR_SYSTEM, internalNote: $note, dismissalReason: $reason);
    }

    public static function staff(Member $member, string $note = ''): self
    {
        return new self(actorType: self::ACTOR_STAFF, member: $member, internalNote: $note);
    }
}
