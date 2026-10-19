<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Security;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * /egov-prijava – enter the one-time code sent to the referent's email.
 */
class EmailOtpController extends Controller
{
    private static string $url_segment = 'egov-prijava';

    private static array $allowed_actions = ['CodeForm'];

    public function Link($action = null): string
    {
        return Controller::join_links('/', static::config()->get('url_segment'), $action);
    }

    protected function init(): void
    {
        parent::init();
        Requirements::css('youwilllikeit/silverstripe-egov: client/dist/egov.css');
        $this->getResponse()->addHeader('Cache-Control', 'private, no-store');
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $member = $this->member();
        if ($member instanceof HTTPResponse) {
            return $member;
        }
        $otp = EmailOtpService::singleton();
        if ($otp->isVerified($request, $member)) {
            return $this->redirect($this->backUrl($request));
        }
        // send automatically on first visit (respecting the cooldown)
        $expired = strtotime((string) $member->EGovOtpExpiresAt) < \SilverStripe\ORM\FieldType\DBDatetime::now()->getTimestamp();
        $sentNow = (!$member->EGovOtpHash || $expired || $otp->attemptsExhausted($member)) ? $otp->send($member) : false;

        return $this->customise([
            'Title' => 'Potvrda prijave',
            'TenantFullName' => TenantConfig::singleton()->fullName(),
            'MaskedEmail' => $this->maskEmail((string) $member->Email),
            'SentNow' => $sentNow,
            'Form' => $this->CodeForm(),
        ])->renderWith('YouWillLikeIT\\EGov\\Admin\\OtpPage');
    }

    public function CodeForm(): Form
    {
        $form = Form::create(
            $this,
            'CodeForm',
            FieldList::create(
                TextField::create('Code', 'Kod iz emaila')
                    ->setAttribute('inputmode', 'numeric')
                    ->setAttribute('autocomplete', 'one-time-code')
                    ->setAttribute('autofocus', 'autofocus')
                    ->setMaxLength(6),
                HiddenField::create('BackURL', null, $this->backUrl($this->getRequest())),
            ),
            FieldList::create(
                FormAction::create('doVerify', 'Potvrdi')->setUseButtonTag(true)->addExtraClass('egov-btn egov-btn--primary'),
                FormAction::create('doResend', 'Pošalji novi kod')->setUseButtonTag(true)->addExtraClass('egov-btn egov-btn--ghost')
                    ->setAttribute('formnovalidate', 'formnovalidate'),
            )
        );
        $form->addExtraClass('egov-form');
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doVerify(array $data, Form $form): HTTPResponse
    {
        $member = $this->member();
        if ($member instanceof HTTPResponse) {
            return $member;
        }
        $otp = EmailOtpService::singleton();
        if ($otp->verify($member, (string) ($data['Code'] ?? ''))) {
            $otp->markVerified($this->getRequest(), $member);
            return $this->redirect($this->safeBack((string) ($data['BackURL'] ?? '')));
        }
        $form->sessionMessage($otp->attemptsExhausted($member)
            ? 'Previše pogrešnih pokušaja. Zatražite novi kod.'
            : 'Kod nije ispravan ili je istekao.', 'error');
        return $this->redirectBack();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doResend(array $data, Form $form): HTTPResponse
    {
        $member = $this->member();
        if ($member instanceof HTTPResponse) {
            return $member;
        }
        $form->sessionMessage(EmailOtpService::singleton()->send($member)
            ? 'Novi kod je poslan.'
            : 'Novi kod možete zatražiti za najviše minut.', 'good');
        return $this->redirectBack();
    }

    private function member(): Member|HTTPResponse
    {
        $member = Security::getCurrentUser();
        return $member ?: $this->redirect(Security::login_url());
    }

    private function backUrl(HTTPRequest $request): string
    {
        return $this->safeBack((string) $request->getVar('BackURL'));
    }

    private function safeBack(string $url): string
    {
        return ($url !== '' && Director::is_site_url($url) && str_starts_with($url, '/')) ? $url : '/admin';
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 2) . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
    }
}
