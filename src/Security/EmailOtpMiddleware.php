<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Security;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Security\Security;

/**
 * Forces the email one-time code after password login for /admin, /egov-datoteke, /egov-izvjestaji.
 * Registered after AuthenticationMiddleware (see _config/security.yml).
 */
class EmailOtpMiddleware implements HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate)
    {
        $otp = EmailOtpService::singleton();
        if (!$otp->isEnabled() || !$otp->isProtectedPath($request->getURL())) {
            return $delegate($request);
        }

        $member = Security::getCurrentUser();
        if (!$member || $otp->isExempt($member) || $otp->isVerified($request, $member)) {
            return $delegate($request);
        }

        $back = '/' . ltrim($request->getURL(true), '/');
        $target = Controller::join_links('/', EmailOtpController::config()->get('url_segment'), '?BackURL=' . rawurlencode($back));

        if ($request->isAjax()) {
            // CMS PJAX requests: force a full page reload to the code page
            $response = HTTPResponse::create('', 401);
            $response->addHeader('X-ControllerURL', $target);
            $response->addHeader('X-Reload', 'true');
            return $response;
        }
        return HTTPResponse::create()->redirect($target, 302);
    }
}
