<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Throwable;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\AuditLogEntry;

/**
 * Writes the access trail. Never throws – auditing must not break the work,
 * but failures are reported to the PSR logger.
 */
class AuditLogger
{
    use Injectable;
    use Configurable;

    /**
     * Do not log repeated "View" of the same case by the same member within N seconds
     * (the CMS reloads the edit form after every action).
     *
     * @config
     */
    private static int $view_dedupe_seconds = 600;

    public function log(string $action, ?ApplicationRequest $request = null, string $details = '', ?Member $member = null): void
    {
        try {
            $member ??= Security::getCurrentUser();
            if ($action === AuditLogEntry::ACTION_VIEW && $request && $member && $this->recentlyViewed($request, $member)) {
                return;
            }
            $httpRequest = $this->httpRequest();
            AuditLogEntry::create([
                'Action' => $action,
                'MemberID' => (int) $member?->ID,
                'MemberName' => $member ? mb_substr((string) $member->getName() . ' <' . $member->Email . '>', 0, 255) : 'Sistem',
                'RequestID' => (int) $request?->ID,
                'TrackingCode' => (string) $request?->TrackingCode,
                'Details' => mb_substr($details, 0, 2000),
                'IP' => mb_substr((string) $httpRequest?->getIP(), 0, 45),
                'UserAgent' => mb_substr((string) $httpRequest?->getHeader('User-Agent'), 0, 255),
            ])->write();
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error('[EGov] Revizijski zapis nije upisan', ['error' => $e->getMessage(), 'action' => $action]);
        }
    }

    private function recentlyViewed(ApplicationRequest $request, Member $member): bool
    {
        $since = date('Y-m-d H:i:s', time() - (int) static::config()->get('view_dedupe_seconds'));
        return AuditLogEntry::get()->filter([
            'Action' => AuditLogEntry::ACTION_VIEW,
            'RequestID' => $request->ID,
            'MemberID' => $member->ID,
            'Created:GreaterThan' => $since,
        ])->exists();
    }

    private function httpRequest(): ?HTTPRequest
    {
        $controller = Controller::curr();
        if (!$controller) {
            return null;
        }
        $request = $controller->getRequest();
        return $request instanceof HTTPRequest ? $request : null;
    }
}
